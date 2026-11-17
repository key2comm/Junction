# High availability, part 1 (backup servers only): before anything else is
# configured, copy the main server's database and files, so this server uses
# the same secrets, app key and certificates as the main one.
# (The main server's side is set up in step 70.)

render "$SS_ROOT/deploy/sbin/softswitch-ha" /usr/local/sbin/softswitch-ha 0755

ha_standby || return 0

pkg_install postgresql-server postgresql-contrib rsync
pgdata=/var/lib/pgsql/data
if [ -f "$pgdata/standby.signal" ]; then
	info "Already a backup of $SS_HA_PEER"
	return 0
fi

[ -n "$SS_HA_PASSWORD" ] || die "set SS_HA_PASSWORD in $SS_CONFIG: the main server prints it when its install finishes"
# A brand-new server has no database worth keeping, so don't ask.
if [ "$SS_ASSUME_YES" = 1 ] || [ ! -f "$pgdata/PG_VERSION" ]; then
	/usr/local/sbin/softswitch-ha clone --yes
else
	/usr/local/sbin/softswitch-ha clone
fi
