# Web stack: nginx + PHP-FPM (+ Composer, Node for asset builds), a
# self-signed certificate so HTTPS works immediately, and the certificate
# helper the admin UI uses to switch to Let's Encrypt.

pkg_install nginx php-fpm php-cli php-common php-pgsql php-pdo php-mbstring \
	php-xml php-intl php-bcmath php-opcache php-process php-gd php-pecl-zip \
	php-sodium composer nodejs /usr/bin/npm certbot openssl unzip \
	policycoreutils-python-utils

# App user: owns the Laravel app, runs PHP-FPM and the queue workers.
getent group "$SS_APP_USER" >/dev/null || groupadd --system "$SS_APP_USER"
getent passwd "$SS_APP_USER" >/dev/null \
	|| useradd --system -g "$SS_APP_USER" -d /var/lib/softswitch -m -s /sbin/nologin "$SS_APP_USER"

# Writes FreeSWITCH gateway files (sip_profiles/carrier is group-writable).
getent group freeswitch >/dev/null && usermod -aG freeswitch "$SS_APP_USER"

install -d -o "$SS_APP_USER" -g nginx -m 0750 "$SS_WEB_ROOT"
install -d -m 0755 /var/www/acme

# --- TLS: self-signed until a real certificate is issued from the admin UI.
tls=/etc/softswitch/tls
install -d -m 0750 -g nginx "$tls"
if [ ! -f "$tls/selfsigned.crt" ]; then
	info "Creating self-signed certificate for $SS_HOSTNAME"
	openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$SS_HOSTNAME" \
		-addext "subjectAltName=DNS:$SS_HOSTNAME,IP:$SS_PUBLIC_IP" \
		-keyout "$tls/selfsigned.key" -out "$tls/selfsigned.crt" 2>/dev/null
	chmod 0640 "$tls/selfsigned.key"; chgrp nginx "$tls/selfsigned.key"
fi
[ -e "$tls/current.crt" ] || ln -sfn "$tls/selfsigned.crt" "$tls/current.crt"
[ -e "$tls/current.key" ] || ln -sfn "$tls/selfsigned.key" "$tls/current.key"
[ -f "$tls/mode" ] || echo "selfsigned " > "$tls/mode"

# --- Certificate helper + the single sudo rule the app user gets.
install -m 0755 "$SS_ROOT/deploy/sbin/softswitch-cert" /usr/local/sbin/softswitch-cert
sudoers="$(mktemp)"
render "$SS_ROOT/deploy/sudoers/softswitch" "$sudoers" 0440
visudo -cqf "$sudoers" || die "generated sudoers file is invalid"
install -m 0440 "$sudoers" /etc/sudoers.d/softswitch
rm -f "$sudoers"

# Reload nginx when certbot renews (renewal itself runs from certbot-renew.timer).
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/softswitch-reload-nginx
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/softswitch-reload-nginx
systemctl enable -q --now certbot-renew.timer 2>/dev/null || warn "certbot-renew.timer not available; set up renewal manually"

# --- PHP-FPM: dedicated pool; disable the stock pool (runs as 'apache').
render "$SS_ROOT/deploy/php-fpm/softswitch.conf" /etc/php-fpm.d/softswitch.conf
[ -f /etc/php-fpm.d/www.conf ] && mv /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.disabled
php-fpm -t -q 2>/dev/null || php-fpm -t || die "php-fpm config test failed"
systemctl enable -q php-fpm
systemctl restart php-fpm

# --- nginx
render "$SS_ROOT/deploy/nginx/softswitch.conf" /etc/nginx/conf.d/softswitch.conf
nginx -t -q || die "nginx config test failed"
systemctl enable -q nginx
systemctl restart nginx

# --- SELinux: keep enforcing, grant only what the app needs.
if selinuxenabled 2>/dev/null; then
	setsebool -P httpd_can_network_connect_db 1   # PHP -> PostgreSQL
	# nginx -> FreeSWITCH WebSocket (wss://<host>/ws): reverse-proxy to an http port.
	semanage port -a -t http_port_t -p tcp "$SS_PBX_WSS_PORT" 2>/dev/null \
		|| semanage port -m -t http_port_t -p tcp "$SS_PBX_WSS_PORT"
	setsebool -P httpd_can_network_relay 1
	semanage fcontext -a -t httpd_sys_rw_content_t "$SS_WEB_ROOT/storage(/.*)?" 2>/dev/null \
		|| semanage fcontext -m -t httpd_sys_rw_content_t "$SS_WEB_ROOT/storage(/.*)?"
	semanage fcontext -a -t httpd_sys_rw_content_t "$SS_WEB_ROOT/bootstrap/cache(/.*)?" 2>/dev/null \
		|| semanage fcontext -m -t httpd_sys_rw_content_t "$SS_WEB_ROOT/bootstrap/cache(/.*)?"
	restorecon -R "$SS_WEB_ROOT" /var/www/acme
fi

info "nginx + PHP-FPM ready: https://$SS_HOSTNAME (self-signed until Let's Encrypt is enabled in the admin UI)"
