# FreeSWITCH configuration: replaces the stock sample config with the
# Softswitch tree from deploy/freeswitch/conf (templates rendered with this
# server's settings and secrets) and installs the routing scripts.
#
# FreeSWITCH is only restarted when the rendered configuration changes, so
# re-running the installer on a live switch doesn't drop calls.

conf="$SS_FS_CONF"
src="$SS_ROOT/deploy/freeswitch/conf"
fs_cli="$SS_FS_PREFIX/bin/fs_cli"
scripts="$SS_FS_PREFIX/share/freeswitch/scripts/softswitch"

export SS_FS_DB_PASSWORD="$(secret FS_DB_PASSWORD)"
export SS_ESL_PASSWORD="$(secret ESL_PASSWORD)"
export SS_INTERNAL_PASSWORD="$(secret INTERNAL_PASSWORD)"

# Keep the stock sample config once, for reference.
if [ -d "$conf" ] && [ ! -f "$conf/.softswitch" ]; then
	stock="$SS_FS_PREFIX/etc/freeswitch.stock"
	if [ -e "$stock" ]; then
		die "$conf is not a Softswitch config and $stock already exists; move one of them aside"
	fi
	mv "$conf" "$stock"
	info "Stock FreeSWITCH config moved to $stock"
fi

# (Tolerates missing directories: on first install the config doesn't exist yet.)
checksum() {
	{ find "$conf" "$scripts" -type f ! -path "$conf/sip_profiles/carrier/*" -print0 2>/dev/null || true; } \
		| sort -z | xargs -0 -r sha256sum | sha256sum
}
before="$(checksum)"

# Render every file in the repo tree; remove files the repo no longer has
# (except generated gateway files).
install -d -m 0750 "$conf"
listing="$(mktemp)"
( cd "$src" && find . -type f | sed 's|^\./||' | sort ) > "$listing"
while read -r rel; do
	render "$src/$rel" "$conf/$rel" 0640
done < "$listing"
( cd "$conf" && find . -type f ! -path './sip_profiles/carrier/*' ! -name .softswitch | sed 's|^\./||' | sort ) \
	| comm -23 - "$listing" | while read -r stale; do rm -f -- "$conf/$stale"; done
rm -f -- "$listing"
touch "$conf/.softswitch"

install -d -m 0750 "$scripts"
install -m 0640 "$SS_ROOT/deploy/freeswitch/scripts/softswitch/"*.lua "$scripts/"

chown -R root:freeswitch "$conf" "$scripts"
find "$conf" "$scripts" -type d -exec chmod 0750 {} +
# Gateway files are written by the app's queue worker (a member of the freeswitch group).
install -d -o freeswitch -g freeswitch -m 2770 "$conf/sip_profiles/carrier"
install -d -o freeswitch -g freeswitch -m 0750 "$SS_FS_LOG/xml_cdr"

# fs_cli on this host picks up the ESL password automatically (root only).
install -m 0600 /dev/null /etc/fs_cli.conf
printf '[default]\nhost => 127.0.0.1\nport => 8021\npassword => %s\n' "$SS_ESL_PASSWORD" > /etc/fs_cli.conf

after="$(checksum)"
if [ "$before" != "$after" ] || ! systemctl is-active -q freeswitch; then
	info "FreeSWITCH configuration changed; restarting"
	systemctl restart freeswitch
else
	info "FreeSWITCH configuration unchanged"
fi

for _ in $(seq 1 30); do
	"$fs_cli" -x status >/dev/null 2>&1 && break
	sleep 2
done
"$fs_cli" -x status >/dev/null 2>&1 || die "FreeSWITCH did not start; check journalctl -u freeswitch and $SS_FS_LOG/freeswitch.log"

# Profiles start a moment after the core answers; give them time.
for profile in carrier internal selftest; do
	running=0
	for _ in $(seq 1 20); do
		if "$fs_cli" -x "sofia status" | awk -v p="$profile" '$1 == p && $2 == "profile" && /RUNNING/ { f = 1 } END { exit !f }'; then
			running=1; break
		fi
		sleep 1
	done
	[ "$running" = 1 ] || die "SIP profile '$profile' is not running; check $SS_FS_LOG/freeswitch.log"
done
info "FreeSWITCH running: $("$fs_cli" -x version | awk 'NR==1')"
info "SIP profiles: carrier on $SS_PUBLIC_IP:$SS_CARRIER_PORT, internal on $SS_PUBLIC_IP:$SS_PBX_PORT"

# Now that FreeSWITCH logs exist, turn on its fail2ban jail.
. "$SS_ROOT/installer/steps/30-fail2ban.sh"
