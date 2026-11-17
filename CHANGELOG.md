# Changelog

## v1.0.1 (not released yet)

A minor fix to the updater.

### Updating
- **Fixed: the web UI kept showing "Update available" after an update.** After a `git pull`, which the README says to run once on older versions, `update.sh` said "Already up to date" and didn't install the new code. The server kept running the old version, so the dashboard was right to say an update was available.
  - The installer now records which code it installed.
  - `update.sh` installs whenever the code in the Junction folder differs from that record, however it got there.

## v1.0.0 (2026-11-17)

### High availability
- **A backup server kept in sync with the main one.**
  - The database is copied continuously over an encrypted connection (PostgreSQL streaming replication), usually within seconds.
  - Files are copied every minute: secrets, the app key, certificates, IVR greetings, branding and uploads.
  - Only the other server may connect, through both the firewall and the database's access rules.
- **Set up with three settings** in `/etc/softswitch/softswitch.conf`: `SS_HA_ROLE`, `SS_HA_PEER` and, on the backup, `SS_HA_PASSWORD`. Then run `sudo ./install.sh` on each server. The main server's install prints what to put on the backup.
- **The backup stays idle:** FreeSWITCH and the background jobs are stopped, and the admin UI shows only the sync status.
- **One-click failover:** an Owner or Admin clicks **Make this the main server** on the backup, or runs `sudo softswitch-ha promote` there. The backup starts taking calls; then you point your DNS name at it. `sudo softswitch-ha clone` turns the old main server into the new backup.
- **Monitoring:**
  - The main server's dashboard shows whether the backup is in sync.
  - A new alert, "Backup server not in sync", fires if the backup disconnects or falls more than 5 minutes behind.
  - The backup emails you if it loses contact with the main server, and again when it's back in sync.
- `sudo softswitch-ha status` shows where either server stands.
- The call self-test now sends its PBX and device test calls straight to the server it runs on, instead of to wherever the hostname points in DNS. So it also checks a backup that was just promoted, before DNS has moved.

### Installer
- **Fixed: on a new server, the install stopped silently in step 90**, right after installing PHP packages. It tried to empty the app's cache before the cache's database table existed. As a result, the admin login was never shown.
- If any step fails, the installer now names the command that failed, with its file and line, and tells you to re-run it. A step can no longer stop without saying why.
- On a new server, FreeSWITCH's first start can be slow, and the installer sometimes gave up at step 60 before it was ready. It now waits up to 3 minutes. If FreeSWITCH or a SIP profile still doesn't start, the installer shows FreeSWITCH's own error lines instead of just pointing at the log.
- Step 80 no longer prints php-fpm's help text. It checked the PHP config with an option that php-fpm doesn't support.
- Re-running the installer no longer prints SELinux's "already defined, modifying instead" notices.

## v0.1.2-beta (2026-11-15)

### Admin users and access levels
- **Settings → Admin users** (Owners only): invite people to manage the server, change their access level, or remove them. It also shows each person's 2FA status and when they were last active.
- **Five access levels:**
  - **Owner**: everything, including managing admins.
  - **Admin**: everything except managing admins.
  - **Operations (NOC)**: customers, carriers, rates, LCR groups, PBX, DIDs, live calls, call records and alerts. No billing or server settings.
  - **Billing**: customers, invoices, payments, billing items and profiles, taxes and billing settings. Can see routing and calls but not change them.
  - **Read-only**: can see everything except server settings, and change nothing.
- **Invitations:** the person gets an email with a link to choose their name and password. The link works once and expires after 7 days. If email isn't set up, you get the link to send yourself. Invitations can be resent or cancelled.
- The menu only shows what each person can open. Pages they can see but not change are marked "View only".
- Admins that already exist become Owners when you update, and so does the first admin the installer creates.

### Updating
- **`sudo ./update.sh`** downloads the latest version from GitHub and applies it, keeping your settings and data. `sudo ./update.sh <release>` switches to a specific release.
- When an update is available, the dashboard shows the command to run.

## v0.1.1-beta (2026-11-14)

### Junction
- The project is now called **Junction** (github.com/key2comm/Junction). The admin UI, SIP User-Agent and API title say Junction. Paths, services and the database keep their `softswitch` names, so upgrading changes nothing on disk.

### Dashboard
- The Server card shows the hostname, IP address, OS and app version.
- **App version** compares this server with the latest release on GitHub ("Up to date" or "Update available"). It checks every 6 hours, or on **Check now**.

### Billing items and billing profiles
- **Billing → Billing items:** a shared catalog of things to put on any customer's bill.
  - Each item has a name, a price, what it's charged "per" (ext, DID…), and monthly or one-time.
  - Prices are in each customer's own currency.
- **Billing → Billing profiles:** named plans made from items, plus how Canada/USA calls are charged:
  - pay per minute;
  - unlimited Canada/USA;
  - a monthly pool of included Canada/USA minutes, then the tariff rate.

  International calls always pay the tariff rate. Canada/USA means +1 except the Caribbean.
- **Billing plan on each customer's page:**
  - choose the profile;
  - change quantities or set the customer's own price;
  - add extra items;
  - see the included minutes used this month and the recent plan charges.
- **New customers:**
  - the New customer form has a Billing profile field;
  - Settings → Client defaults can pre-select one.
- **How charging works:**
  - Monthly items are charged in advance on the 1st, in the customer's time zone.
  - Adding, changing or removing an item mid-month is prorated by days.
  - One-time items are charged when added.
  - Invoices list the plan charges, including next month's, and note the Canada/USA minutes the plan covered.
- **Routing:** calls covered by the plan don't need funds, so prepaid customers on unlimited Canada/USA aren't refused for a low balance. International calls still are.

### Taxes
- **Billing → Taxes:** sales taxes for every Canadian province and territory and every US state (plus DC).
  - Canada is pre-filled with GST/HST/PST/QST for telecom services.
  - US states start at 0%.
  - Any tax can be renamed, re-rated or switched off, and extra taxes or fees can be added per region.
- **Tax region:** each customer has one, set on the customer form (Settings → Client defaults can pre-select it). Customers without one aren't taxed.
- **What gets taxed:**
  - Plan items and call usage are taxed as they're charged, so prepaid balances always include tax.
  - Credits get their tax back.
  - A rate change applies from the next charge.
- **Invoices:**
  - each tax is listed with what it was charged on;
  - your tax registration numbers (Settings → Billing) are printed on every invoice.

## v0.1.0-beta (2026-11-10)

First public beta. Softswitch is a Class 4 (wholesale) softswitch built on FreeSWITCH, with light PBX features, a web admin UI, a customer portal and a REST API. It installs on one Rocky Linux / RHEL-family server with `install.sh`.

This is a beta. Test it on your own traffic before relying on it, and expect changes before 1.0.

### Routing
- Customers, carriers and LCR groups. Calls are routed by least cost, priority or weight, with automatic failover between carriers.
- Carriers own their settings:
  - separate inbound and outbound concurrent call limits;
  - codecs, which apply to every outbound call;
  - one or more SIP trunks (IP/FQDN, port, UDP/TCP/TLS);
  - advanced trunk options: From user, Auth user, From domain, RPID, PAI and outbound proxy.
- Tariffs and carrier rates:
  - CSV import with column matching;
  - tariffs built from carrier rates plus a markup;
  - rates can also be edited one at a time.
- Quality-aware routing: carriers that fall below an LCR group's ASR/PDD bar are pushed down the route list.
- Customer dialing rules: Canada/USA only, all international, or Canada/USA plus chosen countries.
- Built-in route tester, and a live-call self-test (`php artisan softswitch:selftest`) that places real SIP calls through the switch.

### PBX and devices
- PBXs and devices, including shared PBXs and SIP trunk devices with IP authentication, and caller ID rules.
- Minimal hosted PBX: extensions, voicemail, IVR, DIDs and a browser (WebRTC) phone. The web phone works behind NAT without a STUN server.
- DIDs:
  - provider, language and status per DID;
  - CSV import with a sample file and column matching.

### Calls and monitoring
- Admin dashboard:
  - calls in progress, calls today and this month, and ASR/ACD;
  - the last 24 hours (total, answered, failed) and the last 7 days as charts;
  - server CPU, memory, disk and load.
- Live calls: customer, call and carrier, auto-refreshing every 5, 25, 40 or 60 seconds, with hang-up.
- Call records:
  - filtering and CSV export;
  - a per-call detail page;
  - a SIP-signalling PCAP for every call, kept 90 days by default (configurable).
- Carrier quality stats (ASR, ACD, PDD, failures).
- Fraud and operational alerts by email.

### Billing (optional)
- Prepaid and postpaid customers, credit limits, payments and adjustments, and low-balance alerts.
- Monthly invoices in each customer's time zone, emailed to them.
- Carrier payables and carrier invoice reconciliation.
- Billing can be switched off for routing-only use (Settings → Billing).

### Customer portal and API
- Customer portal:
  - an overview with call stats;
  - calls with CSV export;
  - rates, invoices, a web phone and API tokens.
- REST API v1 with bearer tokens, including customer-scoped tokens, and an OpenAPI 3.1 spec.

### Settings and security
- Settings for:
  - SSL certificate (Let's Encrypt), email (SMTP) and API tokens;
  - branding (logo, icon, company name);
  - client defaults;
  - scheduled CDR reports (daily, weekly or monthly; saved on the server, emailed, or uploaded by SFTP).
- Firewall management:
  - whitelist, blacklist and service lockdown;
  - fail2ban bans;
  - carrier SIP trunk IPs are trusted automatically;
  - every change has an automatic rollback timer.
- Each user sees times in their own time zone. SELinux stays enforcing.

### Known limitations
- Single server only. There is no clustering or high availability yet.
- Call captures cover SIP on ports 5060 and 5080 only. TLS (5061) and WebRTC (WSS) signalling is encrypted, so it can't be captured.
- The web phone needs UDP ports 16384–32768 open to the browser.
