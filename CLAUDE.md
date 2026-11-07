# Softswitch

This project is worked on from multiple devices and synced through GitHub.
- Run `git pull` at the start of a session.
- Commit and push finished work so the other devices can pick it up.
- Never commit secrets. Keep machine-specific config in `.env` or `.claude/settings.local.json` (both gitignored).

## Project
**Resuming? Read `docs/RESUME.md` first**: it says where work stopped and what's next.

Class 4 (wholesale carrier) softswitch on FreeSWITCH: routes high-volume traffic between customer and vendor carriers, with light PBX features on the side. See `docs/PLAN.md` for the phases and port layout.
- Server: SSH alias `theswitch` (rocky@theswitch.key2pbx.ca), Rocky 10.2, passwordless sudo.
- Laravel app source is in `web/`. Test with `scripts/test-on-server.sh` (this dev machine has no PHP/Node). Public registration and email verification were removed from the starter kit on purpose; admins are created with `php artisan softswitch:admin`. Privileged actions (certificates) go through queued jobs → `sudo /usr/local/sbin/softswitch-cert`; php-fpm never runs sudo (SELinux stays enforcing).
- Live call testing: `php artisan softswitch:selftest` on the server (as the softswitch user) places real SIP calls via the loopback `selftest` FreeSWITCH profile. Run it after any change to routing, route.lua, FreeSWITCH config or CDR handling. No third-party SIP tools (SIPp) — the user chose the built-in self-test.
- Admin UI: Laravel + Vue 3 + Inertia (Vite), deployed to `/var/www/html` (nginx root `/var/www/html/public`), sharing PostgreSQL with the routing engine. Styled with Tabler (`@tabler/core`, Bootstrap 5 classes; CSS only, with dropdowns and modals in Vue). The user chose Tabler, so don't reintroduce Tailwind. `components/ui/` is a small Tabler-based kit (Button, Input, Card, Dialog...). Use Bootstrap utilities, plus the `ss-*` helpers in `resources/css/app.css` for CSS-grid columns and sibling spacing.
- Installable by anyone: `install.sh` runs `installer/steps/NN-*.sh`; templates in `deploy/` use `@SS_VAR@` from `/etc/softswitch/softswitch.conf`. Keep everything generic (no hardcoded hosts/IPs). Test on theswitch with `scripts/push-to-server.sh`.
- FreeSWITCH is built from public source, so no SignalWire token is needed. Secrets are generated at install time in `/etc/softswitch/secrets`; never commit any.
- Firewall changes must be applied with an automatic rollback timer so a mistake can't lock us out of SSH.
