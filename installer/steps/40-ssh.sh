# SSH hardening: key-only logins, no root login.

dropin=/etc/ssh/sshd_config.d/10-softswitch.conf
admin="${SUDO_USER:-}"

case "$SS_HARDEN_SSH" in
	no)
		rm -f "$dropin"
		info "SSH hardening disabled (SS_HARDEN_SSH=no)"
		return 0 2>/dev/null || exit 0 ;;
	yes) ;;
	auto)
		home="$(getent passwd "$admin" | cut -d: -f6)"
		if [ -z "$admin" ] || [ "$admin" = root ] || [ ! -s "$home/.ssh/authorized_keys" ]; then
			warn "Skipping SSH hardening: run the installer with sudo as a user that logs in with an SSH key, or set SS_HARDEN_SSH=yes"
			return 0 2>/dev/null || exit 0
		fi ;;
	*) die "SS_HARDEN_SSH must be auto, yes or no" ;;
esac

install -m 0600 "$SS_ROOT/deploy/ssh/sshd_config.d/10-softswitch.conf" "$dropin"
sshd -t || { rm -f "$dropin"; die "sshd config test failed; hardening not applied"; }
systemctl reload sshd
info "SSH: keys only, root login disabled"
