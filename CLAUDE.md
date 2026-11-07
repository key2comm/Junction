# Softswitch

This project is worked on from multiple devices and synced through GitHub.
- Run `git pull` at the start of a session.
- Commit and push finished work so the other devices can pick it up.
- Never commit secrets. Keep machine-specific config in `.env` or `.claude/settings.local.json` (both gitignored).

## Project
Class 4 (wholesale carrier) softswitch on FreeSWITCH: routes high-volume traffic between customer and vendor carriers, with light PBX features on the side. See `docs/PLAN.md` for the phases and port layout.
- Server: SSH alias `theswitch` (rocky@theswitch.key2pbx.ca), Rocky 10.2, passwordless sudo.
- Admin UI: Laravel + Vue 3 + Inertia (Vite), deployed to `/var/www/html` (nginx root `/var/www/html/public`), sharing PostgreSQL with the routing engine.
- Installable by anyone: `install.sh` runs `installer/steps/NN-*.sh`; templates in `deploy/` use `@SS_VAR@` from `/etc/softswitch/softswitch.conf`. Keep everything generic (no hardcoded hosts/IPs). Test on theswitch with `scripts/push-to-server.sh`.
- FreeSWITCH is built from public source, so no SignalWire token is needed. Secrets are generated at install time in `/etc/softswitch/secrets`; never commit any.
- Firewall changes must be applied with an automatic rollback timer so a mistake can't lock us out of SSH.
