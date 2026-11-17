#!/usr/bin/env bash
# Junction updater: download the latest release from GitHub and apply it.
#
#   sudo ./update.sh                 # update to the latest version
#   sudo ./update.sh v0.1.1-beta     # switch to a specific release
#   sudo ./update.sh --yes           # install.sh options (--yes, --steps…) are passed on
#
# Your settings (/etc/softswitch/softswitch.conf), database and data are kept.
set -Eeuo pipefail

# Everything is inside main() so bash reads the whole file before the update
# replaces it.
main() {
	SS_ROOT="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
	# shellcheck source=installer/lib.sh
	. "$SS_ROOT/installer/lib.sh"

	local target="" pass=()
	while [ $# -gt 0 ]; do
		case "$1" in
			-h|--help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
			--steps|--config) pass+=("$1" "${2:?$1 needs a value}"); shift 2 ;;
			-*) pass+=("$1"); shift ;;
			*) [ -z "$target" ] || die "only one version can be given"; target="$1"; shift ;;
		esac
	done

	[ "$(id -u)" -eq 0 ] || die "run as root: sudo $0"
	command -v git >/dev/null || die "git is not installed"
	[ -d "$SS_ROOT/.git" ] || die "$SS_ROOT is not a git checkout; clone Junction from GitHub to use update.sh"

	# Run git as whoever owns the checkout, so root never leaves files behind
	# that the owner can't change.
	local owner
	owner="$(stat -c %U "$SS_ROOT")"
	g() {
		if [ "$owner" = root ]; then git -C "$SS_ROOT" "$@"
		else runuser -u "$owner" -- git -C "$SS_ROOT" "$@"; fi
	}

	[ -z "$(g status --porcelain --untracked-files=no)" ] \
		|| die "files in $SS_ROOT were changed by hand (see: git -C $SS_ROOT status). Undo or commit them, then run update.sh again."

	# What's installed and running (not just what's in this folder: a "git pull"
	# downloads code without installing it).
	local web before installed
	web="$( (set +u; . /etc/softswitch/softswitch.conf 2>/dev/null; printf '%s' "${SS_WEB_ROOT:-/var/www/html}") )"
	before="$(cat "$web/VERSION" 2>/dev/null || echo unknown)"
	installed="$(cat /etc/softswitch/installed-commit 2>/dev/null || true)"

	log "Checking GitHub for updates"
	g fetch --quiet --tags --force origin

	if [ -n "$target" ]; then
		g rev-parse -q --verify "refs/tags/$target^{commit}" >/dev/null \
			|| die "no release called $target (see: git -C $SS_ROOT tag)"
		g checkout --quiet "$target"
	else
		local branch
		branch="$(g symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null | sed 's|^origin/||')"
		branch="${branch:-main}"
		g checkout --quiet "$branch"
		g merge --quiet --ff-only "origin/$branch" \
			|| die "can't update $branch: it has local commits that aren't on GitHub"
	fi

	local after current
	after="$(cat "$SS_ROOT/web/VERSION" 2>/dev/null || echo unknown)"
	current="$(g rev-parse HEAD)"
	# Up to date only if this exact code was installed (installs before 1.0.1 didn't
	# record it: then the installed version must match).
	if { [ -n "$installed" ] && [ "$installed" = "$current" ]; } || { [ -z "$installed" ] && [ "$before" = "$after" ] && [ "$after" != unknown ]; }; then
		log "Already up to date (Junction $after). Nothing to do."
		info "To reinstall anyway: sudo $SS_ROOT/install.sh"
		exit 0
	fi

	if [ "$before" = "$after" ]; then
		log "Updating Junction $after to the latest code"
	else
		log "Updating Junction $before → $after"
	fi
	export SS_UPDATED_FROM="$before"
	exec "$SS_ROOT/install.sh" "${pass[@]}"
}

main "$@"
exit
