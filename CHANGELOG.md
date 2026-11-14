# Changelog

## Unreleased

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
