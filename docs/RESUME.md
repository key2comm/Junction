# Where we left off

_Last updated: 2026-11-07. All 8 phases of `docs/PLAN.md` are done._

## How to resume

1. Open the project: `cd ~/projects/Softswitch && git pull`, then `code .`
2. Start Claude Code and say: **"Read docs/RESUME.md and let's continue."**
3. Claude also reads `CLAUDE.md` (project rules) and `docs/PLAN.md` (the full plan) automatically.

## State

- **Phases 1–8 of `docs/PLAN.md` are done**, deployed to the test server, and verified there:
  - hardening (firewall, fail2ban, SSH)
  - FreeSWITCH, built from source with no SignalWire token
  - Class 4 routing engine (LCR, failover, limits, funds, quality-aware routing)
  - billing: rating, ledger, invoices, vendor payables and reconciliation, SMTP from the UI, low-balance alerts
  - REST API at `/api/v1` with an OpenAPI spec, and the customer portal at `/portal`
  - vendor quality stats, live calls, and fraud and operational alerts
  - minimal PBX (phase 8): extensions, voicemail, IVR with WAV greeting, DIDs, WebRTC at `wss://<host>/ws`, and a JsSIP web phone (admin: PBX → account → Web phone; portal: Web phone)
- **Settings → Billing** (new): the built-in billing can be turned off ("Routing only") for switches where another platform bills the customers. Off means:
  - no invoices, invoice emails or low-balance alerts
  - balances, payments, invoices and vendor payables are hidden (UI, portal and API return 404), but nothing is deleted
  - `softswitch_route()` skips the funds check (`softswitch_billing_enabled()` reads `app_settings`)
  - Sell and buy rates still apply: LCR, the margin guard, and CDR rating. Usage is still posted to the ledger, so a later switch back on is consistent; the page warns that balances may then be negative.
  - The page also holds invoice options (payment terms; auto-email, moved from the Email page). New billing options go there.
- **Admin UI restyled with Tabler** (the user's choice, replacing the starter kit's Tailwind/shadcn look). Every admin page was screenshot-checked with headless Firefox. Portal pages share the same components but weren't individually screenshot-checked.
- **Tests:** 185 automated tests pass (`scripts/test-on-server.sh theswitch`). The built-in call self-test passes all 11 scenarios, 4 of them PBX.
- **Git:** everything is committed and pushed to `github.com/key2comm/Softswitch` (private), on branch `main`.
- **Test server** `theswitch.key2pbx.ca` (147.182.148.63, Rocky 10, 1 vCPU, 1.7 GB):
  - All services are running.
  - The database is empty except the admin login: no accounts, extensions, calls, or tokens.

## What's next (the user decides)

1. **Try the PBX in a browser.** Create a customer account, add two extensions on its PBX page, and open the Web phone for each in two browser tabs or devices. Call between them, call a number out through a routing plan, and dial `*97`. WebRTC *media* (audio) has not been verified yet, because the self-test only checks signalling. Do this after Let's Encrypt is enabled: browsers refuse `wss://` to a self-signed certificate.
2. **Real-carrier test.** Needs the IP of a real test customer or vendor from the user. It would also be the first real check of inbound DIDs.
3. **Setup the user can do in the admin UI** (https://theswitch.key2pbx.ca):
   - Settings → SSL certificate: Let's Encrypt (DNS already points at the server). **Needed for the web phone.**
   - Settings → Email (SMTP). For Microsoft 365, "Authenticated SMTP" may need enabling on the mailbox.
   - User menu → My account → Profile: change the default admin login `admin@theswitch.key2pbx.ca`. The initial password is on the server: `sudo grep ADMIN_INITIAL_PASSWORD /etc/softswitch/secrets`.
4. **Production sizing.** This server handles roughly 30–50 concurrent calls. Production needs more CPU, or several FreeSWITCH media servers behind Kamailio.
5. **Offered but not yet requested:**
   - PBX in the REST API, and customers managing their own extensions in the portal
   - voicemail to email, voicemail list/playback in the UI, and message-waiting lights
   - per-account dialling rules (for example 10-digit North American numbers → prepend 1)
   - a proper welcome email for new portal logins (it currently uses Laravel's "Reset Password" wording)
   - server-generated PDF invoices (needs a new PDF library dependency, so ask first)

## Things to remember

- **UI checks without a browser here:** headless Firefox driven over WebDriver BiDi (a small stdlib-only Python client) can log in and screenshot pages. It needs a login. Create a temporary admin only with the user's OK, and delete it afterwards. JavaScript .click() goes around overlays, so check clickability with real pointer actions (input.performActions) and document.elementFromPoint, including on pages with dialogs (Profile, Security). The layout is Tabler's horizontal one (menu at the top, the user's choice): admin items are grouped into Calls, Billing, Routing and Settings (SSL certificate, Email, API tokens) dropdowns; the user menu holds My account (profile, security, appearance), positioned by CSS because there is no Bootstrap JS.

- **Deploying:** commit, then `scripts/push-to-server.sh theswitch -- --steps 90` (copy plus app step). Add `60` when `deploy/freeswitch` changes and `80` when `deploy/nginx` changes. Run the self-test after anything that touches calls: `sudo -u softswitch bash -c 'cd /var/www/html && php artisan softswitch:selftest'`.
- **No PHP or Node on this laptop.** Tests run on the server through `scripts/test-on-server.sh`. To change npm dependencies, regenerate `package-lock.json` on the server (`npm install --package-lock-only`) and copy it back.
- **SSH:** the key has a passphrase. Load it into the agent Claude's shell uses (`env SSH_AUTH_SOCK=<Claude's socket> ssh-add ~/.ssh/id_rsa`); fish terminals have their own agent.
- **WebRTC needs WSS end to end:** browsers put `WSS` in `Via`, so FreeSWITCH must use `wss-binding` (loopback 7443, `tls/wss.pem`), not `ws-binding`. The installer makes the certificate.
- **nginx FastCGI buffers** were raised to 32k: Vite's `Link` preload header plus cookies overflowed 4k and gave 502s on the real hostname.
- **Emergency calls are not supported** by the PBX; the UI says so.
- **Permission boundaries:** Claude Code's permission system blocks building or running third-party code (SIPp was refused). The user chose the built-in self-test instead. Don't work around these blocks; ask the user.
- **PHP gotcha** that bit us four times: numeric-looking array keys such as `'503'` or `'44'` become integers. Cast back to string when comparing or returning prefixes. The same applies to IVR option keys. Store them as a JSON object with `(object)`.
- **Name clashes:** don't name helpers `call()` or `post()` in commands and tests, because Laravel already uses those names. Scoped route parameters must match relation names (`{pbxExtension}` → `pbxExtensions()`).
- **Self-test cleanup** removes its data but not live calls left over from manual testing. End only `sofia/selftest/` channels if needed.
