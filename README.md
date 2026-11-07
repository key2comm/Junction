# Softswitch

An open Class 4 (wholesale carrier) softswitch built on FreeSWITCH. It routes VoIP traffic between customer and vendor carriers using least-cost routing, and includes a few light PBX features.

> Status: early development. Not ready for production traffic.

## Install

Requirements: Rocky Linux, AlmaLinux, RHEL, or CentOS Stream 9/10. Use a fresh server with a public IP and root/sudo access. No SignalWire token is needed, because FreeSWITCH is built from public source.

```sh
git clone https://github.com/key2comm/Softswitch.git
cd Softswitch
sudo ./install.sh
```

The first run creates `/etc/softswitch/softswitch.conf`. To change a setting (carrier IPs, ports, versions), edit that file and run `sudo ./install.sh` again. Every step can be safely re-run.

Generated secrets (event socket password and others) are kept in `/etc/softswitch/secrets`, readable by root only.

| Step | What it does |
|---|---|
| 10-base | Enables EPEL and CRB, and installs base tools |
| 20-firewall | Sets up the nftables firewall, with an automatic 3-minute rollback until you confirm SSH still works |
| 30-fail2ban | Bans repeated SSH and SIP login failures |
| 40-ssh | Turns off SSH password and root login (only when you already log in with a key) |
| 50-freeswitch | Builds FreeSWITCH, sofia-sip, and spandsp from source |
| 60-freeswitch-config | Secures the stock config and starts FreeSWITCH |
| 70-postgresql | Sets up PostgreSQL on localhost: database, user, and a generated password |
| 80-web | Sets up nginx and PHP-FPM, a self-signed certificate, and the Let's Encrypt helper |
| 90-app | Deploys the admin UI (Laravel + Vue + Inertia) to `/var/www/html`, runs migrations, starts the queue worker and scheduler, creates the first admin, and syncs gateways and the firewall |
| 95-selftest | Places real test calls through the switch and checks routing, failover, rejections, the funds cut-off, and billing |

### REST API

Create a token in **Settings → API tokens**, then call `https://<your-server>/api/v1/...` with `Authorization: Bearer ss_…`. The OpenAPI 3.1 spec is at `/api/v1/openapi.json`. Customer-scoped tokens only see that customer's account, calls, and invoices.

```sh
curl -H "Authorization: Bearer $TOKEN" https://switch.example.com/api/v1/cdrs/summary
```

### Self-test

The switch can test itself without any external tools. A loopback-only SIP profile acts as a fake customer and fake vendors, and every call goes through the real routing engine and billing:

```sh
sudo -u softswitch bash -c 'cd /var/www/html && php artisan softswitch:selftest'
```

It creates temporary `SELFTEST-*` records and removes them afterwards. Run it after changing FreeSWITCH config or upgrading.

After installing, open `https://<your-server>` and sign in as `admin@<hostname>`. Get the initial password with `sudo grep ADMIN_INITIAL_PASSWORD /etc/softswitch/secrets`. To switch to a trusted certificate, go to **Settings → SSL certificate**. Public sign-up is disabled, so add more admins with:

```sh
sudo -u softswitch bash -c 'cd /var/www/html && php artisan softswitch:admin someone@example.com'
```

## Development

The repo is synced across devices through GitHub. `git pull` before you start; commit and `git push` when you're done.

- `scripts/push-to-server.sh theswitch -- --steps 90` copies the committed code to the test server and runs the chosen installer steps there.
- `scripts/test-on-server.sh theswitch` runs the Laravel tests, frontend build, and type check on the server. It works from devices that don't have PHP or Node installed.

See [docs/PLAN.md](docs/PLAN.md) for the architecture and roadmap.
