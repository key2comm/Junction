#!/usr/bin/env bash
# Dev helper: run the Laravel test suite and frontend checks on a server that
# has PHP/Node (useful from devices without them installed locally).
#   scripts/test-on-server.sh [ssh-host] [-- extra `php artisan test` args]
# Uses a scratch copy in ~/softswitch-ci; never touches the deployed app.
set -euo pipefail
host="${1:-theswitch}"; shift || true
[ "${1:-}" = "--" ] && shift
cd "$(git rev-parse --show-toplevel)"
# Send the working tree (including uncommitted changes) of web/ only.
tar --exclude=./vendor --exclude=./node_modules --exclude=./public/build -C web -cf - . \
	| ssh "$host" 'mkdir -p ~/softswitch-ci/web && cd ~/softswitch-ci/web && find . -mindepth 1 -maxdepth 1 ! -name vendor ! -name node_modules -exec rm -rf {} + && tar -xf -'
ssh "$host" "cd ~/softswitch-ci/web && set -e
	composer install --no-interaction --no-progress --quiet
	[ -f .env ] || { cp .env.example .env; php artisan key:generate -q; }
	npm ci --no-audit --no-fund --loglevel=error >/dev/null
	echo '--- frontend build'; npm run build --silent >/dev/null && echo ok
	echo '--- type check'; npm run types:check --silent && echo ok
	echo '--- tests'; php artisan test --compact $*"
