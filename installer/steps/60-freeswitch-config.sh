# Interim FreeSWITCH config: secures the stock sample config so it is safe to
# start. Replaced by the Softswitch Class 4 config in a later phase.

conf="$SS_FS_CONF"
[ -f "$conf/vars.xml" ] || die "no FreeSWITCH config at $conf (run step 50 first)"

# Sample extensions ship with password 1234: replace with a random secret.
sed -i -E "s|(data=\"default_password=)[^\"]*\"|\1$(secret FS_DEFAULT_PASSWORD)\"|" "$conf/vars.xml"
sed -i -E \
	-e "s|(data=\"internal_sip_port=)[0-9]+\"|\1${SS_PBX_PORT}\"|" \
	-e "s|(data=\"internal_tls_port=)[0-9]+\"|\1${SS_PBX_TLS_PORT}\"|" \
	-e "s|(data=\"external_sip_port=)[0-9]+\"|\1${SS_CARRIER_PORT}\"|" \
	"$conf/vars.xml"

# Event socket: localhost only, random password (stock is ClueCon on all interfaces).
esl_pw="$(secret ESL_PASSWORD)"
esl="$conf/autoload_configs/event_socket.conf.xml"
sed -i -E \
	-e "s|(<param name=\"listen-ip\" value=\")[^\"]*\"|\1127.0.0.1\"|" \
	-e "s|(<param name=\"password\" value=\")[^\"]*\"|\1${esl_pw}\"|" \
	"$esl"
grep -q 'name="listen-ip" value="127.0.0.1"' "$esl" || die "could not set event socket listen-ip"

# fs_cli on this host picks up the ESL password automatically (root only).
install -m 0600 /dev/null /etc/fs_cli.conf
printf '[default]\nhost => 127.0.0.1\nport => 8021\npassword => %s\n' "$esl_pw" > /etc/fs_cli.conf

# RTP port range must match the firewall.
sw="$conf/autoload_configs/switch.conf.xml"
sed -i -E \
	-e "s|<!-- *(<param name=\"rtp-start-port\" value=\")[0-9]+(\"/>) *-->|\1${SS_RTP_START}\2|" \
	-e "s|<!-- *(<param name=\"rtp-end-port\" value=\")[0-9]+(\"/>) *-->|\1${SS_RTP_END}\2|" \
	-e "s|(<param name=\"rtp-start-port\" value=\")[0-9]+\"|\1${SS_RTP_START}\"|" \
	-e "s|(<param name=\"rtp-end-port\" value=\")[0-9]+\"|\1${SS_RTP_END}\"|" \
	"$sw"

chown -R freeswitch:freeswitch "$conf"

systemctl restart freeswitch
for _ in $(seq 1 30); do
	fs_cli -x status >/dev/null 2>&1 && break
	sleep 2
done
fs_cli -x status >/dev/null 2>&1 || die "FreeSWITCH did not start; check journalctl -u freeswitch and $SS_FS_LOG/freeswitch.log"
info "FreeSWITCH running: $(fs_cli -x version | awk 'NR==1')"

# Now that FreeSWITCH logs exist, turn on its fail2ban jail.
. "$SS_ROOT/installer/steps/30-fail2ban.sh"
