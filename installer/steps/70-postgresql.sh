# PostgreSQL: the shared database for the Laravel app and the FreeSWITCH
# routing engine. Listens on localhost only.

pkg_install postgresql-server postgresql-contrib

pgdata=/var/lib/pgsql/data
if [ ! -f "$pgdata/PG_VERSION" ]; then
	info "Initialising PostgreSQL cluster"
	postgresql-setup --initdb >/dev/null
fi

# Password (SCRAM) auth for the softswitch database over localhost TCP,
# placed above the distro defaults so it takes precedence.
hba="$pgdata/pg_hba.conf"
if ! grep -q '# BEGIN softswitch' "$hba"; then
	tmp="$(mktemp)"
	{
		echo '# BEGIN softswitch (managed by install.sh)'
		echo "host    $SS_DB_NAME    all    127.0.0.1/32    scram-sha-256"
		echo "host    $SS_DB_NAME    all    ::1/128         scram-sha-256"
		echo '# END softswitch'
		cat "$hba"
	} > "$tmp"
	install -o postgres -g postgres -m 0600 "$tmp" "$hba"
	rm -f "$tmp"
fi

systemctl enable -q postgresql
systemctl restart postgresql

db_pw="$(secret DB_PASSWORD)"
psql_admin() { runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qAtX "$@"; }

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

PGPASSWORD="$db_pw" psql -h 127.0.0.1 -U "$SS_DB_USER" -d "$SS_DB_NAME" -qAtX -c 'SELECT 1' >/dev/null \
	|| die "cannot connect to PostgreSQL as $SS_DB_USER"
info "PostgreSQL ready: database $SS_DB_NAME, user $SS_DB_USER (password in /etc/softswitch/secrets)"
