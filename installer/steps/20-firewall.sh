# nftables firewall, applied with an automatic rollback so a bad rule
# can't lock you out of SSH (on first install AND whenever the rules change).

pkg_install nftables

if systemctl is-active -q firewalld 2>/dev/null; then
	warn "firewalld is running; disabling it (Softswitch manages nftables directly)"
	systemctl disable --now firewalld
fi

# The helper the app uses (through sudo) for the carrier allowlist and the
# Settings -> Firewall whitelist, blacklist and lockdown.
render "$SS_ROOT/deploy/sbin/softswitch-firewall" /usr/local/sbin/softswitch-firewall 0755

# Sets filled by the helper from the app's database. Until the first sync they
# start empty (the policy file flushes every set it manages).
carriers=/etc/nftables/softswitch-carriers.nft
[ -f "$carriers" ] || install -m 0600 /dev/null "$carriers"
policy=/etc/nftables/softswitch-policy.nft
if [ ! -s "$policy" ]; then
	printf 'flush set inet softswitch %s\n' trusted trusted6 blocked blocked6 locked_tcp locked_udp > "$policy"
	chmod 0600 "$policy"
fi

rules=/etc/nftables/softswitch.nft
new="$(mktemp)"
render "$SS_ROOT/deploy/nftables/softswitch.nft" "$new" 0600
nft -c -f "$new" || { rm -f "$new"; die "firewall rules failed validation; nothing applied"; }

boot_conf=/etc/sysconfig/nftables.conf
include_line="include \"$rules\""

# Wait for the admin to prove access still works; returns 1 if they don't.
confirm_firewall() {
	rm -f /run/softswitch-fw-confirmed
	[ "$SS_ASSUME_YES" = 1 ] && return 0
	info "Open a NEW SSH session to this server to prove access still works, then either"
	info "type 'yes' here, or run:  sudo touch /run/softswitch-fw-confirmed"
	for _ in $(seq 1 34); do
		[ -e /run/softswitch-fw-confirmed ] && return 0
		if [ -t 0 ] && read -r -t 5 answer && [ "$answer" = yes ]; then return 0; fi
		[ -t 0 ] || sleep 5
	done
	return 1
}

systemctl stop softswitch-fw-rollback.timer 2>/dev/null || true
systemctl reset-failed softswitch-fw-rollback.timer softswitch-fw-rollback.service 2>/dev/null || true

if grep -qxF "$include_line" "$boot_conf" 2>/dev/null && nft list table inet softswitch >/dev/null 2>&1; then
	if cmp -s "$new" "$rules"; then
		# Already installed and unchanged: just reload.
		rm -f "$new"
		nft -f "$rules"
		info "Firewall reloaded (rules unchanged)"
	else
		# The rules changed: apply them with a 3-minute rollback to the old ones.
		cp -p "$rules" "$rules.prev"
		install -m 0600 "$new" "$rules"
		rm -f "$new"
		systemd-run -q --unit=softswitch-fw-rollback --on-active=180 \
			/bin/sh -c "cp -p '$rules.prev' '$rules' && /usr/sbin/nft -f '$rules'"
		nft -f "$rules"
		info "Updated firewall rules applied. They will be ROLLED BACK in 3 minutes unless confirmed."
		confirm_firewall || die "firewall change not confirmed; the previous rules will be restored automatically"
		systemctl stop softswitch-fw-rollback.timer
		rm -f "$rules.prev"
		info "Updated firewall rules confirmed"
	fi
else
	# First install: apply with a 3-minute rollback.
	install -m 0600 "$new" "$rules"
	rm -f "$new"
	systemd-run -q --unit=softswitch-fw-rollback --on-active=180 \
		/usr/sbin/nft destroy table inet softswitch
	nft -f "$rules"
	info "Firewall applied. It will be ROLLED BACK in 3 minutes unless confirmed."
	confirm_firewall || die "firewall not confirmed; it will be rolled back automatically"
	systemctl stop softswitch-fw-rollback.timer
	grep -qxF "$include_line" "$boot_conf" || echo "$include_line" >> "$boot_conf"
	systemctl enable -q nftables
	info "Firewall confirmed and enabled at boot"
fi

# Static carrier IPs from the installer config (the app adds the rest).
if [ -n "$SS_CARRIER_IPS" ]; then
	printf '%s\n' $SS_CARRIER_IPS | /usr/local/sbin/softswitch-firewall apply >/dev/null
	info "Static carrier IPs allowed: $SS_CARRIER_IPS"
fi
