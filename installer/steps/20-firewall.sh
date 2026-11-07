# nftables firewall, applied with an automatic rollback so a bad rule
# can't lock you out of SSH.

pkg_install nftables

if systemctl is-active -q firewalld 2>/dev/null; then
	warn "firewalld is running; disabling it (Softswitch manages nftables directly)"
	systemctl disable --now firewalld
fi

render "$SS_ROOT/deploy/nftables/softswitch.nft" /etc/nftables/softswitch.nft 0600

install -m 0755 "$SS_ROOT/deploy/sbin/softswitch-firewall" /usr/local/sbin/softswitch-firewall

# The carrier allowlist is managed by softswitch-firewall (from the app's
# database). Until the first sync, start with an empty set.
carriers=/etc/nftables/softswitch-carriers.nft
[ -f "$carriers" ] || install -m 0600 /dev/null "$carriers"

nft -c -f /etc/nftables/softswitch.nft || die "firewall rules failed validation; nothing applied"

boot_conf=/etc/sysconfig/nftables.conf
include_line='include "/etc/nftables/softswitch.nft"'

if grep -qxF "$include_line" "$boot_conf" 2>/dev/null && nft list table inet softswitch >/dev/null 2>&1; then
	# Already installed and confirmed before: just reload.
	nft -f /etc/nftables/softswitch.nft
	info "Firewall reloaded"
	return 0 2>/dev/null || exit 0
fi

# First install: apply with a 3-minute rollback.
systemctl stop softswitch-fw-rollback.timer 2>/dev/null || true
systemd-run -q --unit=softswitch-fw-rollback --on-active=180 \
	/usr/sbin/nft destroy table inet softswitch
rm -f /run/softswitch-fw-confirmed
nft -f /etc/nftables/softswitch.nft
info "Firewall applied. It will be ROLLED BACK in 3 minutes unless confirmed."

confirmed=0
if [ "$SS_ASSUME_YES" = 1 ]; then
	confirmed=1
else
	info "Open a NEW SSH session to this server to prove access still works, then either"
	info "type 'yes' here, or run:  sudo touch /run/softswitch-fw-confirmed"
	for _ in $(seq 1 34); do
		if [ -e /run/softswitch-fw-confirmed ]; then confirmed=1; break; fi
		if [ -t 0 ] && read -r -t 5 answer && [ "$answer" = yes ]; then confirmed=1; break; fi
		[ -t 0 ] || sleep 5
	done
fi

if [ "$confirmed" != 1 ]; then
	die "firewall not confirmed; it will be rolled back automatically"
fi

systemctl stop softswitch-fw-rollback.timer
grep -qxF "$include_line" "$boot_conf" || echo "$include_line" >> "$boot_conf"
systemctl enable -q nftables
info "Firewall confirmed and enabled at boot"

# Static carrier IPs from the installer config (the app adds the rest).
if [ -n "$SS_CARRIER_IPS" ]; then
	printf '%s\n' $SS_CARRIER_IPS | /usr/local/sbin/softswitch-firewall apply >/dev/null
	info "Static carrier IPs allowed: $SS_CARRIER_IPS"
fi
