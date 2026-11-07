# Where we left off

_Last updated: 2026-11-07. Paused at the user's request after finishing phase 7._

## How to resume

1. Open the project: `cd ~/projects/Softswitch && git pull`, then `code .`
2. Start Claude Code and say: **"Read docs/RESUME.md and let's continue."**
3. Claude also reads `CLAUDE.md` (project rules) and `docs/PLAN.md` (the full plan) automatically.

## State

- **Phases 1–7 of `docs/PLAN.md` are done**, deployed to the test server, and verified there:
  - hardening (firewall, fail2ban, SSH)
  - FreeSWITCH, built from source with no SignalWire token
  - Class 4 routing engine (LCR, failover, limits, funds, quality-aware routing)
  - billing: rating, ledger, invoices, vendor payables and reconciliation, SMTP from the UI, low-balance alerts
  - REST API at `/api/v1` with an OpenAPI spec
  - customer portal at `/portal`
  - vendor quality stats, live calls, and fraud and operational alerts
- **Tests:** 176 automated tests pass (`scripts/test-on-server.sh theswitch`). The built-in call self-test passes all 7 scenarios.
- **Git:** everything is committed and pushed to `github.com/key2comm/Softswitch` (private), on branch `main`.
- **Test server** `theswitch.key2pbx.ca` (147.182.148.63, Rocky 10, 1 vCPU, 1.7 GB):
  - All services are running.
  - The database is empty except the admin login: no accounts, calls, or tokens.
  - No calls are in progress.

## What's next (the user decides)

1. **Phase 8: minimal PBX.** Extensions, voicemail, a simple IVR, and WebRTC through nginx WSS, with outside calls routed through the same LCR. This is the last phase in the plan.
2. **Real-carrier test.** Everything so far is verified with the built-in self-test only. This needs the IP of a real test customer or vendor from the user.
3. **Setup the user can do in the admin UI** (https://theswitch.key2pbx.ca):
   - Settings → SSL certificate: Let's Encrypt (DNS already points at the server)
   - Settings → Email: SMTP. The user chose to enter SMTP in the UI. For Microsoft 365, "Authenticated SMTP" may need enabling on the mailbox.
   - Settings → Profile: change the default admin login `admin@theswitch.key2pbx.ca`. The initial password is on the server: `sudo grep ADMIN_INITIAL_PASSWORD /etc/softswitch/secrets`.
4. **Production sizing.** This server handles roughly 30–50 concurrent calls. Production needs more CPU, or several FreeSWITCH media servers behind Kamailio.
5. **Polish, offered but not yet requested:**
   - a proper welcome email for new portal logins (it currently uses Laravel's "Reset Password" wording)
   - server-generated PDF invoices (needs a new PDF library dependency, so ask first)

## Things to remember

- **Deploying:** commit, then `scripts/push-to-server.sh theswitch -- --steps 90` (copy plus app step). Run the self-test after anything that touches calls: `sudo -u softswitch bash -c 'cd /var/www/html && php artisan softswitch:selftest'`.
- **No PHP or Node on this laptop.** Tests run on the server through `scripts/test-on-server.sh`.
- **Permission boundaries:** Claude Code's permission system blocks building or running third-party code (SIPp was refused). The user chose the built-in self-test instead. Don't work around these blocks; ask the user.
- **PHP gotcha** that bit us four times: numeric-looking array keys such as `'503'` or `'44'` become integers. Cast back to string when comparing or returning prefixes.
- **Name clashes:** don't name helpers `call()` or `post()` in commands and tests, because Laravel already uses those names.
- **Self-test cleanup** removes its data but not live calls left over from manual testing. End only `sofia/selftest/` channels if needed.
