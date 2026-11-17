# PostgreSQL: the shared database for the Laravel app and the FreeSWITCH
# routing engine. Listens on localhost only, unless high availability is on:
# then the other server (only) may connect for replication, over TLS.

pkg_install postgresql-server postgresql-contrib

pgdata=/var/lib/pgsql/data
if [ ! -f "$pgdata/PG_VERSION" ]; then
	info "Initialising PostgreSQL cluster"
	postgresql-setup --initdb >/dev/null
fi

# Password (SCRAM) auth for the softswitch database over localhost TCP, and
# replication from the other HA server, placed above the distro defaults so
# they take precedence. Rewritten on every run (a backup's copy came from the
# main server, which names the other server).
hba="$pgdata/pg_hba.conf"
tmp="$(mktemp)"
{
	echo '# BEGIN softswitch (managed by install.sh)'
	echo "host    $SS_DB_NAME    all    127.0.0.1/32    scram-sha-256"
	echo "host    $SS_DB_NAME    all    ::1/128         scram-sha-256"
	if [ -n "$SS_HA_ROLE" ]; then
		echo "hostssl replication softswitch_repl $SS_HA_PEER/32 scram-sha-256"
		echo "hostssl $SS_DB_NAME softswitch_repl $SS_HA_PEER/32 scram-sha-256"
	fi
	echo '# END softswitch'
	sed '/# BEGIN softswitch/,/# END softswitch/d' "$hba"
} > "$tmp"
install -o postgres -g postgres -m 0600 "$tmp" "$hba"
rm -f "$tmp"

systemctl enable -q postgresql
systemctl restart postgresql

psql_admin() { runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qAtX "$@"; }

# A backup's database is a read-only copy of the main server's: roles,
# passwords and data all come from there.
if [ "$(psql_admin -c 'SELECT pg_is_in_recovery()')" = t ]; then
	info "PostgreSQL ready: read-only copy of the main server's database ($SS_HA_PEER)"
	return 0
fi

db_pw="$(secret DB_PASSWORD)"

if [ -z "$(psql_admin -c "SELECT 1 FROM pg_roles WHERE rolname = '$SS_DB_USER'")" ]; then
	psql_admin -c "CREATE ROLE \"$SS_DB_USER\" LOGIN"
fi
# Always (re)set the password so it matches /etc/softswitch/secrets.
psql_admin <<-SQL
	SET password_encryption = 'scram-sha-256';
	ALTER ROLE "$SS_DB_USER" PASSWORD '$db_pw';
SQL

if [ -z "$(psql_admin -c "SELECT 1 FROM pg_database WHERE datname = '$SS_DB_NAME'")" ]; then
	psql_admin -c "CREATE DATABASE \"$SS_DB_NAME\" OWNER \"$SS_DB_USER\" ENCODING 'UTF8' TEMPLATE template0"
fi

# FreeSWITCH's role: may only connect and (after step 90) execute the routing functions.
fs_pw="$(secret FS_DB_PASSWORD)"
if [ -z "$(psql_admin -c "SELECT 1 FROM pg_roles WHERE rolname = '$SS_FS_DB_USER'")" ]; then
	psql_admin -c "CREATE ROLE \"$SS_FS_DB_USER\" LOGIN"
fi
psql_admin <<-SQL
	SET password_encryption = 'scram-sha-256';
	ALTER ROLE "$SS_FS_DB_USER" PASSWORD '$fs_pw' CONNECTION LIMIT 200;
	REVOKE ALL ON DATABASE "$SS_DB_NAME" FROM PUBLIC;
	GRANT CONNECT ON DATABASE "$SS_DB_NAME" TO "$SS_DB_USER", "$SS_FS_DB_USER";
SQL

# --- High availability: the main server (or a promoted backup) ---
if [ -n "$SS_HA_ROLE" ]; then
	# TLS for replication (self-signed: the SCRAM password with channel binding
	# authenticates both ends).
	if [ ! -s "$pgdata/server.key" ]; then
		openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=junction-db" \
			-keyout "$pgdata/server.key" -out "$pgdata/server.crt" >/dev/null 2>&1 || die "could not create the database TLS certificate"
		chown postgres:postgres "$pgdata/server.key" "$pgdata/server.crt"
		chmod 0600 "$pgdata/server.key"
	fi
	repl_pw="$(secret REPL_PASSWORD)"
	if [ -z "$(psql_admin -c "SELECT 1 FROM pg_roles WHERE rolname = 'softswitch_repl'")" ]; then
		psql_admin -c "CREATE ROLE softswitch_repl REPLICATION LOGIN"
	fi
	psql_admin <<-SQL
		SET password_encryption = 'scram-sha-256';
		ALTER ROLE softswitch_repl PASSWORD '$repl_pw';
		GRANT CONNECT ON DATABASE "$SS_DB_NAME" TO softswitch_repl;
		ALTER SYSTEM SET listen_addresses = '*';
		ALTER SYSTEM SET ssl = 'on';
		-- Keep enough WAL for a backup that was offline for a while to catch up.
		ALTER SYSTEM SET wal_keep_size = '2GB';
	SQL
	# The app reads replication status (Dashboard → High availability, alerts).
	psql_admin -c "GRANT pg_monitor TO \"$SS_DB_USER\""
	# Files that travel to the backup (packed by softswitch-ha). Only postgres can read them.
	psql_admin -d "$SS_DB_NAME" <<-SQL
		CREATE SCHEMA IF NOT EXISTS softswitch_ha AUTHORIZATION postgres;
		REVOKE ALL ON SCHEMA softswitch_ha FROM PUBLIC;
		CREATE TABLE IF NOT EXISTS softswitch_ha.files (
			name text PRIMARY KEY,
			kind text NOT NULL,
			sha256 text NOT NULL,
			content bytea NOT NULL,
			updated_at timestamptz NOT NULL DEFAULT now()
		);
	SQL
else
	psql_admin <<-SQL
		ALTER SYSTEM RESET listen_addresses;
		ALTER SYSTEM RESET ssl;
		ALTER SYSTEM RESET wal_keep_size;
	SQL
fi
# listen_addresses only changes on a restart; do that only when it's needed.
psql_admin -c 'SELECT pg_reload_conf()' >/dev/null
sleep 1
if [ "$(psql_admin -c 'SELECT count(*) FROM pg_settings WHERE pending_restart')" != 0 ]; then
	systemctl restart postgresql
fi

PGPASSWORD="$db_pw" psql -h 127.0.0.1 -U "$SS_DB_USER" -d "$SS_DB_NAME" -qAtX -c 'SELECT 1' >/dev/null \
	|| die "cannot connect to PostgreSQL as $SS_DB_USER"
info "PostgreSQL ready: database $SS_DB_NAME, user $SS_DB_USER (password in /etc/softswitch/secrets)"
