#!/usr/bin/env bash
# Junction updater: download the newest release from GitHub, check its
# signature, and apply it.
#
#   sudo ./update.sh                 # update to the newest release
#   sudo ./update.sh v1.0.16         # switch to a specific release
#   sudo ./update.sh --main          # the latest code, not a release (unsigned; for testing)
#   sudo ./update.sh --yes           # install.sh options (--yes, --steps…) are passed on
#
# Your settings (/etc/softswitch/softswitch.conf), database and data are kept.
# Releases are signed. This server trusts the release key in
# /etc/softswitch/release-signers (saved when Junction was first installed and
# never changed by an update), and refuses a release it can't verify.
set -Eeuo pipefail

# Everything is inside main() so bash reads the whole file before the update
# replaces it.
main() {
	SS_ROOT="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
	# shellcheck source=installer/lib.sh
	. "$SS_ROOT/installer/lib.sh"

	local target="" main=0 pass=()
	while [ $# -gt 0 ]; do
		case "$1" in
			-h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
			--main) main=1; shift ;;
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

	[ "$main" = 0 ] || [ -z "$target" ] || die "give a release or --main, not both"

	# The release key this server trusts. Servers installed before releases were
	# signed take it from the code they already run, before anything is downloaded.
	local signers=/etc/softswitch/release-signers
	if [ ! -s "$signers" ]; then
		[ -s "$SS_ROOT/release-signers" ] || die "no release key: $signers is missing and this version doesn't include one"
		install -m 0644 "$SS_ROOT/release-signers" "$signers"
		log "Trusting the release key in $SS_ROOT/release-signers from now on"
	fi

	log "Checking GitHub for updates"
	g fetch --quiet --tags --force origin

	if [ "$main" = 0 ]; then
		# The newest release: the highest vX.Y.Z tag (not pre-releases like v1.2.0-beta).
		if [ -z "$target" ]; then
			target="$(g tag -l 'v*' --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1)"
			[ -n "$target" ] || die "no releases found on GitHub"
		fi
		g rev-parse -q --verify "refs/tags/$target^{tag}" >/dev/null \
			|| die "no signed release called $target (see: git -C $SS_ROOT tag)"
		# Checked as root (the trusted key file is root's), before any of the
		# release's code is checked out or run.
		git -c safe.directory="$SS_ROOT" -C "$SS_ROOT" -c gpg.format=ssh -c gpg.ssh.allowedSignersFile="$signers" \
			verify-tag "$target" >/dev/null 2>&1 \
			|| die "release $target is not signed with the release key this server trusts ($signers). Not installing it."
		log "Release $target: signature verified"
		g -c advice.detachedHead=false checkout --quiet "refs/tags/$target"
	else
		warn "--main installs the latest code, which isn't a release and isn't signed"
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
