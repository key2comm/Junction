# Deploy the Laravel admin UI / API (web/) to $SS_WEB_ROOT, build it, migrate,
# and run its queue worker + scheduler under systemd.

pkg_install rsync

app="$SS_WEB_ROOT"
home=/var/lib/softswitch
as_app() { (cd "$app" && runuser -u "$SS_APP_USER" -- env HOME="$home" COMPOSER_HOME="$home/.composer" \
	npm_config_cache="$home/.npm" "$@"); }
artisan() { as_app php artisan "$@"; }

# --- Code. .env, storage/ and build outputs survive redeploys.
rsync -a --delete \
	--exclude=/.env --exclude=/storage/ --exclude=/vendor/ --exclude=/node_modules/ \
	--exclude=/public/build/ --exclude=/public/hot --exclude='/bootstrap/cache/*.php' \
	"$SS_ROOT/web/" "$app/"
rsync -a --ignore-existing "$SS_ROOT/web/storage/" "$app/storage/"
chown -R "$SS_APP_USER:nginx" "$app"
find "$app" -type d -exec chmod 0750 {} +
find "$app" -type f -exec chmod 0640 {} +
chmod 0750 "$app/artisan"
# The app writes here; nginx must not need to.
chmod -R u+rwX,g-w "$app/storage" "$app/bootstrap/cache"
command -v restorecon >/dev/null && restorecon -R "$app"

# --- .env (first run creates it; DB settings are always kept in sync).
env_file="$app/.env"
if [ ! -f "$env_file" ]; then
	install -o "$SS_APP_USER" -g "$SS_APP_USER" -m 0600 "$app/.env.example" "$env_file"
fi
set_env() { # set_env KEY VALUE - replace (or append) KEY=VALUE, also if commented out
	local key="$1" val="$2" esc
	esc="$(printf '%s' "$val" | sed 's/[\\&|]/\\&/g')"
	if grep -qE "^#? *${key}=" "$env_file"; then
		sed -i -E "s|^#? *${key}=.*|${key}=${esc}|" "$env_file"
	else
		printf '%s=%s\n' "$key" "$val" >> "$env_file"
	fi
}
set_env APP_NAME Junction
# Internal names stay "softswitch" whatever the product is called, so a rename
# never empties the cache or signs everyone out.
set_env CACHE_PREFIX softswitch-cache-
set_env SESSION_COOKIE softswitch-session
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$SS_HOSTNAME"
set_env LOG_CHANNEL daily
set_env DB_CONNECTION pgsql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 5432
set_env DB_DATABASE "$SS_DB_NAME"
set_env DB_USERNAME "$SS_DB_USER"
set_env DB_PASSWORD "$(secret DB_PASSWORD)"
# A backup's database is read-only: sessions and the cache live in files there,
# and queued jobs run straight away (softswitch-ha promote switches these back).
if ha_standby; then app_store=file; app_queue=sync; else app_store=database; app_queue=database; fi
set_env SESSION_DRIVER "$app_store"
set_env SESSION_SECURE_COOKIE true
set_env QUEUE_CONNECTION "$app_queue"
set_env CACHE_STORE "$app_store"
set_env SOFTSWITCH_HA_ROLE "$SS_HA_ROLE"
set_env SOFTSWITCH_HA_PEER "$SS_HA_PEER"
set_env SOFTSWITCH_PUBLIC_IP "$SS_PUBLIC_IP"
set_env SOFTSWITCH_ESL_PASSWORD "$(secret ESL_PASSWORD)"
set_env SOFTSWITCH_INTERNAL_USER "$SS_INTERNAL_USER"
set_env SOFTSWITCH_INTERNAL_PASSWORD "$(secret INTERNAL_PASSWORD)"
set_env SOFTSWITCH_MAX_CALL_DURATION "$SS_MAX_CALL_DURATION"
# Dashboard → App version (a token only for a private repository; never logged).
[ -z "${SS_UPDATE_REPO:-}" ] || set_env SOFTSWITCH_UPDATE_REPO "$SS_UPDATE_REPO"
[ -z "${SS_UPDATE_TOKEN:-}" ] || set_env SOFTSWITCH_UPDATE_TOKEN "$SS_UPDATE_TOKEN"
set_env SOFTSWITCH_SELFTEST_PORT "$SS_SELFTEST_PORT"
set_env SOFTSWITCH_CARRIER_PORT "$SS_CARRIER_PORT"
set_env SOFTSWITCH_FS_GATEWAY_DIR "$SS_FS_CONF/sip_profiles/carrier"
set_env SOFTSWITCH_FS_DIRECTORY_DIR "$SS_FS_CONF/directory/pbx"
set_env SOFTSWITCH_FS_ACL_DIR "$SS_FS_CONF/acl"
set_env SOFTSWITCH_FS_GREETING_DIR "$SS_FS_GREETINGS"
set_env SOFTSWITCH_FS_CDR_ERROR_DIR "$SS_FS_LOG/xml_cdr"
set_env SOFTSWITCH_HOSTNAME "$SS_HOSTNAME"
set_env SOFTSWITCH_PBX_PORT "$SS_PBX_PORT"
set_env SOFTSWITCH_STATIC_CARRIER_IPS "$SS_CARRIER_IPS"
chown "$SS_APP_USER:$SS_APP_USER" "$env_file"; chmod 0600 "$env_file"

# --- Dependencies + assets.
info "Installing PHP dependencies"
as_app composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if ha_standby; then
	# The main server's key (it decrypts settings such as the SMTP password).
	key="$(runuser -u postgres -- psql -qAtX -d "$SS_DB_NAME" -c "SELECT convert_from(content, 'UTF8') FROM softswitch_ha.files WHERE name = 'app_key'" | sed -n 's/^APP_KEY=//p')"
	[ -n "$key" ] || die "the main server's app key hasn't arrived yet; check that softswitch-ha runs on the main server, then re-run"
	set_env APP_KEY "$key"
else
	grep -qE '^APP_KEY=.+' "$env_file" || artisan key:generate --force --no-interaction
fi

# Stale route/config caches from the previous deploy would hide new routes
# from Wayfinder (which generates the frontend route helpers during the build).
# Only the cached files: optimize:clear would also empty the database cache,
# whose table doesn't exist yet on a new server (migrate runs further down).
for what in config route view event; do
	artisan "$what:clear" --no-interaction >/dev/null
done

info "Building frontend assets (slow on small servers)"
as_app npm ci --no-audit --no-fund --loglevel=error
# The build's output goes to a log (it is long); a failure shows its end. The
# first build after new code has occasionally failed once and then passed, so
# it is retried once before giving up. The log is opened by root, so it lives in
# a root-only folder: in the app user's home, that user could replace it with a
# symlink and have root write (through the build, which runs as that user) to
# any file.
install -d -o root -g root -m 0700 /var/log/softswitch-install
build_log=/var/log/softswitch-install/frontend-build.log
if ! as_app npm run build >"$build_log" 2>&1; then
	tail -n 25 "$build_log" >&2
	warn "the frontend build failed; trying once more (full output: $build_log)"
	as_app npm run build >"$build_log" 2>&1 || { tail -n 40 "$build_log" >&2; die "the frontend build failed (full output: $build_log)"; }
fi
rm -rf "$app/node_modules"

# --- Database + caches.
if ha_standby; then
	# Everything below changes the database; a backup gets it all from the main server.
	artisan optimize --no-interaction >/dev/null
	install -d -o "$SS_APP_USER" -g "$SS_APP_USER" -m 0750 "$app/storage/app/ha"
	for unit in softswitch-scheduler.service softswitch-queue.service softswitch-capture.service 		softswitch-ha.service softswitch-ha.timer softswitch-ha-promote.service softswitch-ha-promote.path; do
		render "$SS_ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit"
	done
	systemctl daemon-reload
	systemctl disable -q --now softswitch-queue softswitch-scheduler 2>/dev/null || true
	systemctl enable -q --now softswitch-ha.timer softswitch-ha-promote.path
	systemctl restart php-fpm
	/usr/local/sbin/softswitch-ha tick || warn "the first sync check failed; it runs again every minute"
	code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$SS_HOSTNAME:443:127.0.0.1" "https://$SS_HOSTNAME/login")"
	[ "$code" = 200 ] || die "admin UI did not respond (HTTP $code); see $app/storage/logs and /var/log/php-fpm/softswitch-error.log"
	info "Backup server ready: in sync with the main server at $SS_HA_PEER"
	info "Its admin UI shows the sync status and the button to make it the main server."
	return 0
fi

artisan migrate --force --no-interaction

# FreeSWITCH's database role may only execute the routing functions.
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qAtX -d "$SS_DB_NAME" <<-SQL
	GRANT EXECUTE ON FUNCTION softswitch_identify(inet, text) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_route(bigint, text, uuid, timestamptz, integer) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_release(uuid) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_pbx_caller(text) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_pbx_dial(bigint, text) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_did(text) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_number_blocked(bigint, text, text) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_endpoint(bigint) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_identify_endpoint(inet, text, text) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_device(bigint) TO "$SS_FS_DB_USER";
	GRANT EXECUTE ON FUNCTION softswitch_gateway_options(bigint[]) TO "$SS_FS_DB_USER";
SQL
artisan optimize --no-interaction >/dev/null

# --- First admin login (only if there are no users yet).
admin_email="${SS_ADMIN_EMAIL:-admin@$SS_HOSTNAME}"
admin_pw="$(secret ADMIN_INITIAL_PASSWORD)"
artisan softswitch:admin "$admin_email" --password="$admin_pw" --if-none >/dev/null

# --- Background services.
# SIP capture (Settings -> Call captures): tcpdump runs confined (netutils_t)
# and may only write netutils_tmp_t; the queue worker reads and prunes the files.
install -d -o "$SS_APP_USER" -g "$SS_APP_USER" -m 0750 /var/lib/softswitch/capture
if command -v semanage >/dev/null 2>&1; then
	semanage fcontext -a -t netutils_tmp_t '/var/lib/softswitch/capture(/.*)?' >/dev/null 2>&1 \
		|| semanage fcontext -m -t netutils_tmp_t '/var/lib/softswitch/capture(/.*)?' >/dev/null
	restorecon -R /var/lib/softswitch/capture
fi

for unit in softswitch-queue.service softswitch-scheduler.service softswitch-capture.service 	softswitch-ha.service softswitch-ha.timer softswitch-ha-promote.service softswitch-ha-promote.path; do
	render "$SS_ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable -q softswitch-queue softswitch-scheduler softswitch-capture
systemctl restart softswitch-queue softswitch-scheduler php-fpm softswitch-capture
# High availability: the main server packs files for the backup every minute.
if [ -n "$SS_HA_ROLE" ]; then
	systemctl enable -q --now softswitch-ha.timer
	/usr/local/sbin/softswitch-ha tick || warn "packing files for the backup failed; it runs again every minute"
else
	systemctl disable -q --now softswitch-ha.timer 2>/dev/null || true
fi
systemctl disable -q --now softswitch-ha-promote.path 2>/dev/null || true

# Push gateways and the carrier allowlist from the database to the switch.
artisan softswitch:sync --no-interaction || warn "switch sync failed; it will be retried by the scheduler"

code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$SS_HOSTNAME:443:127.0.0.1" "https://$SS_HOSTNAME/login")"
[ "$code" = 200 ] || die "admin UI did not respond (HTTP $code); see $app/storage/logs and /var/log/php-fpm/softswitch-error.log"

info "Admin UI: https://$SS_HOSTNAME"
[ -n "${SS_UPDATED_FROM:-}" ] || info "First admin login: $admin_email (the password is shown when the install finishes)"
