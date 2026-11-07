# fail2ban: SSH always; FreeSWITCH SIP auth failures once FreeSWITCH is installed.

pkg_install fail2ban-server
# The EL "fail2ban" meta-package would pull in firewalld actions; we use nftables.
rm -f /etc/fail2ban/jail.d/00-firewalld.conf

if [ -f "$SS_FS_LOG/freeswitch.log" ]; then
	SS_F2B_FREESWITCH=true
else
	SS_F2B_FREESWITCH=false
fi
export SS_F2B_FREESWITCH

render "$SS_ROOT/deploy/fail2ban/jail.d/softswitch.local" /etc/fail2ban/jail.d/softswitch.local
install -m 0644 "$SS_ROOT/deploy/fail2ban/filter.d/freeswitch.local" /etc/fail2ban/filter.d/freeswitch.local

fail2ban-client -t >/dev/null || die "fail2ban config test failed"
systemctl enable -q fail2ban
systemctl restart fail2ban
info "fail2ban active (freeswitch jail: $SS_F2B_FREESWITCH)"
