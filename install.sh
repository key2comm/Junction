#!/usr/bin/env bash
# Softswitch installer.
#
#   sudo ./install.sh                 # run all steps
#   sudo ./install.sh --steps 20,30   # run selected steps only
#   sudo ./install.sh --yes           # non-interactive (skip firewall confirmation wait)
#
# Settings live in /etc/softswitch/softswitch.conf (created from
# softswitch.conf.example on first run). Re-running is safe: every step is
# idempotent, so edit the config and run again to apply changes.
set -Eeuo pipefail

SS_ROOT="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
export SS_ROOT
# shellcheck source=installer/lib.sh
. "$SS_ROOT/installer/lib.sh"

SS_CONFIG=/etc/softswitch/softswitch.conf
SS_ONLY_STEPS=""
SS_ASSUME_YES=0

while [ $# -gt 0 ]; do
	case "$1" in
		--config) SS_CONFIG="$2"; shift 2 ;;
		--steps)  SS_ONLY_STEPS="$2"; shift 2 ;;
		--yes|-y) SS_ASSUME_YES=1; shift ;;
		-h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) die "unknown option: $1" ;;
	esac
done
export SS_ASSUME_YES

[ "$(id -u)" -eq 0 ] || die "run as root: sudo $0"
detect_os

if [ ! -f "$SS_CONFIG" ]; then
	install -d -m 0750 "$(dirname "$SS_CONFIG")"
	install -m 0640 "$SS_ROOT/softswitch.conf.example" "$SS_CONFIG"
	log "Created $SS_CONFIG from defaults (edit it and re-run to change settings)"
fi
# shellcheck disable=SC1090
. "$SS_CONFIG"
resolve_defaults
show_settings

for step in "$SS_ROOT"/installer/steps/[0-9][0-9]-*.sh; do
	num="$(basename "$step" | cut -c1-2)"
	if [ -n "$SS_ONLY_STEPS" ] && [[ ",$SS_ONLY_STEPS," != *",$num,"* ]]; then
		continue
	fi
	log "Step $(basename "$step" .sh)"
	( . "$step" )
done

log "Done."
