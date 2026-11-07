# Shared helpers for install.sh and its steps. Sourced, not executed.
# NOTE: runs with pipefail. Never pipe into a reader that exits early
# (head, grep -q, awk exit): the writer gets SIGPIPE and the installer dies.

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Sets SS_OS_ID, SS_EL (major version). Supported: Rocky, Alma, RHEL, CentOS Stream 9/10.
detect_os() {
	[ -r /etc/os-release ] || die "cannot read /etc/os-release"
	. /etc/os-release
	SS_OS_ID="$ID"
	SS_EL="${VERSION_ID%%.*}"
	case "$ID" in
		rocky|almalinux|rhel|centos) ;;
		*) die "unsupported OS '$ID' (supported: Rocky, Alma, RHEL, CentOS Stream 9/10)" ;;
	esac
	case "$SS_EL" in
		9|10) ;;
		*) die "unsupported EL version $VERSION_ID (need 9 or 10)" ;;
	esac
	export SS_OS_ID SS_EL
}

# Fill in empty settings with auto-detected values.
resolve_defaults() {
	: "${SS_HOSTNAME:=}" "${SS_PUBLIC_IP:=}" "${SS_SSH_PORT:=}"
	[ -n "$SS_HOSTNAME" ]  || SS_HOSTNAME="$(hostname -f 2>/dev/null || hostname)"
	[ -n "$SS_PUBLIC_IP" ] || SS_PUBLIC_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '!f{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); f=1}}')"
	[ -n "$SS_SSH_PORT" ]  || SS_SSH_PORT="$(sshd -T 2>/dev/null | awk '$1=="port" && !f{print $2; f=1}')"
	[ -n "$SS_SSH_PORT" ]  || SS_SSH_PORT=22
	: "${SS_PBX_PORT:=5060}" "${SS_PBX_TLS_PORT:=5061}" "${SS_CARRIER_PORT:=5080}" "${SS_SELFTEST_PORT:=5090}"
	: "${SS_RTP_START:=16384}" "${SS_RTP_END:=32768}"
	: "${SS_CARRIER_IPS:=}" "${SS_HARDEN_SSH:=auto}"
	: "${SS_FREESWITCH_REF:=v1.11.3}" "${SS_SOFIA_SIP_REF:=v1.13.19}" "${SS_SPANDSP_REF:=v3.1.1}"
	: "${SS_WEB_ROOT:=/var/www/html}" "${SS_APP_USER:=softswitch}"
	: "${SS_DB_NAME:=softswitch}" "${SS_DB_USER:=softswitch}"
	: "${SS_ADMIN_EMAIL:=admin@$SS_HOSTNAME}"
	: "${SS_FS_DB_USER:=softswitch_fs}" "${SS_INTERNAL_USER:=freeswitch}"
	: "${SS_MAX_CALL_DURATION:=14400}" "${SS_PROGRESS_TIMEOUT:=10}" "${SS_ANSWER_TIMEOUT:=60}"
	: "${SS_MAX_SESSIONS:=1000}" "${SS_MAX_SESSIONS_PER_SECOND:=100}"
	: "${SS_BUILD_DIR:=/usr/local/src/softswitch}"
	: "${SS_FS_PREFIX:=/usr/local/freeswitch}"
	# FreeSWITCH uses an FHS layout under its prefix.
	SS_FS_CONF="$SS_FS_PREFIX/etc/freeswitch"
	SS_FS_LOG="$SS_FS_PREFIX/var/log/freeswitch"
	SS_FS_RUN="$SS_FS_PREFIX/var/run/freeswitch"
	for v in $(compgen -v SS_); do export "${v?}"; done
}

show_settings() {
	info "OS:          $SS_OS_ID $SS_EL"
	info "Hostname:    $SS_HOSTNAME"
	info "Public IP:   $SS_PUBLIC_IP"
	info "SSH port:    $SS_SSH_PORT"
	info "SIP ports:   PBX $SS_PBX_PORT/$SS_PBX_TLS_PORT, carriers $SS_CARRIER_PORT"
	info "RTP range:   $SS_RTP_START-$SS_RTP_END"
	info "Carrier IPs: ${SS_CARRIER_IPS:-(none yet)}"
}

# render SRC DST [MODE] - copy a template, replacing @SS_VAR@ with its value.
render() {
	local src="$1" dst="$2" mode="${3:-0644}" tmp v val
	tmp="$(mktemp)"
	cp "$src" "$tmp"
	for v in $(compgen -v SS_); do
		val="${!v}"
		val="${val//\\/\\\\}"; val="${val//|/\\|}"; val="${val//&/\\&}"
		sed -i "s|@${v}@|${val}|g" "$tmp"
	done
	if grep -qE '@SS_[A-Z0-9_]+@' "$tmp"; then
		rm -f "$tmp"
		die "template $src has unknown variables: $(grep -oE '@SS_[A-Z0-9_]+@' "$src" | sort -u | tr '\n' ' ')"
	fi
	install -D -m "$mode" "$tmp" "$dst"
	rm -f "$tmp"
}

# secret NAME - print a persistent random secret, creating it on first use.
# Stored in /etc/softswitch/secrets (root only). Never in the repo.
secret() {
	local name="$1" f=/etc/softswitch/secrets val
	install -d -m 0750 /etc/softswitch
	[ -f "$f" ] || install -m 0600 /dev/null "$f"
	val="$(sed -n "s/^${name}=//p" "$f" | awk 'NR==1')"
	if [ -z "$val" ]; then
		val="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
		printf '%s=%s\n' "$name" "$val" >> "$f"
	fi
	printf '%s' "$val"
}

pkg_install() { dnf -y -q install "$@"; }
