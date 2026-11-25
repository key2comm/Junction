# Deploy the Laravel admin UI / API (web/) to $SS_WEB_ROOT, build it, migrate,
# and run its queue worker + scheduler under systemd.

# Who owns what:
#  - The live code ($SS_WEB_ROOT) is root's: the app (php-fpm, queue, scheduler,
#    all running as $SS_APP_USER) can read it but not change it, so a flaw in
#    the app can't plant code that survives. It writes only storage/.
#  - bootstrap/cache holds PHP the app runs (cached config and routes), so root
#    writes it (optimize_app).
#  - Composer and npm run third-party install scripts, so they run as their own
#    user ($build_user) in their own copy, never as root or as the app.
#  - nginx may read public/ only (an ACL); PHP files go to php-fpm.

pkg_install rsync acl

app="$SS_WEB_ROOT"
home=/var/lib/softswitch
build_user=softswitch-build
build_home=/var/lib/softswitch-build
stage="$build_home/web"
as_app() { (cd "$app" && runuser -u "$SS_APP_USER" -- env HOME="$home" "$@"); }
artisan() { as_app php artisan "$@"; }
as_build() { (cd "$stage" && runuser -u "$build_user" -- env HOME="$build_home" COMPOSER_HOME="$build_home/.composer" \
	npm_config_cache="$build_home/.npm" "$@"); }
# Rebuild the caches in bootstrap/cache as root, and the compiled views (which
# live in storage/) as the app. Booting the app as root must not leave
# root-owned files in storage/ (e.g. today's log), which the app couldn't write.
optimize_app() {
	(cd "$app" && php artisan config:cache -q && php artisan route:cache -q && php artisan event:cache -q)
	artisan view:cache -q
	chown -R "root:$SS_APP_USER" "$app/bootstrap/cache"
	find "$app/bootstrap/cache" -type f -exec chmod 0640 {} +
	find "$app/storage" ! -user "$SS_APP_USER" -exec chown -h "$SS_APP_USER:$SS_APP_USER" {} +
}

# --- Build, in the build user's copy (no secrets there: it gets .env.example).
getent group "$build_user" >/dev/null || groupadd --system "$build_user"
id "$build_user" >/dev/null 2>&1 || useradd --system -g "$build_user" -d "$build_home" -s /sbin/nologin "$build_user"
install -d -o "$build_user" -g "$build_user" -m 0700 "$build_home"
rsync -a --delete --exclude=/vendor/ --exclude=/.env "$SS_ROOT/web/" "$stage/"
chown -R "$build_user:$build_user" "$stage"
[ -f "$stage/.env" ] || { install -o "$build_user" -g "$build_user" -m 0600 "$stage/.env.example" "$stage/.env"; as_build php artisan key:generate -q --no-interaction; }

info "Installing PHP dependencies"
as_build composer install --no-dev --optimize-autoloader --no-interaction --no-progress

info "Building frontend assets (slow on small servers)"
as_build npm ci --no-audit --no-fund --loglevel=error
# The build's output goes to a log (it is long); a failure shows its end. The
# first build after new code has occasionally failed once and then passed, so
# it is retried once before giving up. Root opens the log, so it lives in a
# root-only folder that no other user can plant a symlink in.
install -d -o root -g root -m 0700 /var/log/softswitch-install
build_log=/var/log/softswitch-install/frontend-build.log
if ! as_build npm run build >"$build_log" 2>&1; then
	tail -n 25 "$build_log" >&2
	warn "the frontend build failed; trying once more (full output: $build_log)"
	as_build npm run build >"$build_log" 2>&1 || { tail -n 40 "$build_log" >&2; die "the frontend build failed (full output: $build_log)"; }
fi
rm -rf "$stage/node_modules"

# --- Code: the build goes live. .env and storage/ survive redeploys; caches
# from the old code are removed (optimize_app makes new ones further down).
rsync -a --delete --exclude=/.env --exclude=/storage/ --exclude=/public/hot \
	--exclude='/bootstrap/cache/config.php' --exclude='/bootstrap/cache/routes-*.php' --exclude='/bootstrap/cache/events.php' \
	"$stage/" "$app/"
rm -f "$app/bootstrap/cache/config.php" "$app"/bootstrap/cache/routes-*.php "$app/bootstrap/cache/events.php"
rsync -a --ignore-existing "$SS_ROOT/web/storage/" "$app/storage/"
chown -R "root:$SS_APP_USER" "$app"
chown -R "$SS_APP_USER:$SS_APP_USER" "$app/storage"
find "$app" -path "$app/storage" -prune -o -type d -exec chmod 0750 {} +
find "$app" -path "$app/storage" -prune -o -type f -exec chmod 0640 {} +
chmod 0750 "$app/artisan"
chmod -R u+rwX,g-w,o-rwx "$app/storage"
# nginx: through the folder to public/, and read public/ (static files).
setfacl -m u:nginx:x "$app"
setfacl -R -m u:nginx:rX "$app/public"
if command -v semanage >/dev/null 2>&1; then
	# bootstrap/cache used to be writable by the web app; now only storage/ is.
	semanage fcontext -d "$SS_WEB_ROOT/bootstrap/cache(/.*)?" >/dev/null 2>&1 || true
fi
command -v restorecon >/dev/null && restorecon -R "$app"

# --- .env (first run creates it; DB settings are always kept in sync).
env_file="$app/.env"
if [ ! -f "$env_file" ]; then
	install -o root -g "$SS_APP_USER" -m 0640 "$app/.env.example" "$env_file"
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
# The app reads its settings but can't change them.
chown "root:$SS_APP_USER" "$env_file"; chmod 0640 "$env_file"

if ha_standby; then
	# The main server's key (it decrypts settings such as the SMTP password).
	key="$(runuser -u postgres -- psql -qAtX -d "$SS_DB_NAME" -c "SELECT convert_from(content, 'UTF8') FROM softswitch_ha.files WHERE name = 'app_key'" | sed -n 's/^APP_KEY=//p')"
	[ -n "$key" ] || die "the main server's app key hasn't arrived yet; check that softswitch-ha runs on the main server, then re-run"
	set_env APP_KEY "$key"
else
	grep -qE '^APP_KEY=.+' "$env_file" || set_env APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
fi
artisan view:clear -q --no-interaction

# --- Database + caches.
if ha_standby; then
	# Everything below changes the database; a backup gets it all from the main server.
	optimize_app
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
optimize_app

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
