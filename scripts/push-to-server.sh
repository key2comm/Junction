#!/usr/bin/env bash
# Dev helper: copy the committed tree to a server and optionally run the installer.
#   scripts/push-to-server.sh [ssh-host] [-- install.sh args...]
# Only committed files are sent (git archive), so commit first.
set -euo pipefail
host="${1:-theswitch}"; shift || true
[ "${1:-}" = "--" ] && shift
cd "$(git rev-parse --show-toplevel)"
git diff --quiet HEAD || echo "WARN: uncommitted changes are NOT sent" >&2
git archive --format=tar HEAD | ssh "$host" 'rm -rf ~/softswitch && mkdir -p ~/softswitch && tar -xf - -C ~/softswitch'
echo "Copied $(git rev-parse --short HEAD) to $host:~/softswitch"
if [ $# -gt 0 ] || [ -n "${RUN:-}" ]; then
	ssh -t "$host" "cd ~/softswitch && sudo ./install.sh $*"
fi
