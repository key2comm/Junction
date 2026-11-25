# Runbooks

What to do when something goes wrong, step by step. Every command runs on the
switch as root unless it says otherwise. `app$` means run it as the app user:
`sudo -u softswitch php artisan ...` from `/var/www/html`.

## Recovery targets

| Target | Goal | How it's met |
| --- | --- | --- |
| **RPO** (data you can lose) | 1 hour | With a backup server (HA): seconds, by replication. Without one: Settings → Backups → How often → **Every hour**, with an off-site copy (SFTP or S3). |
| **RTO** (time until calls flow again) | 1 hour | With HA: `softswitch-ha promote` plus a DNS change, about 5 minutes. Without HA: install a new server and restore (below). A restore drill shows whether it fits in an hour. |

A backup that only exists on the switch is lost with the switch. Turn on an
off-site copy, and set a **backup password**. Only encrypted backups carry the
app key, and without the key the restored SIP and SMTP passwords can't be read.

Write down how long each drill took, with the date, at the bottom of this file.

---

## 1. Restore from a backup

Use this when the database is lost or damaged, or the server is gone and there's
no backup server.

**Before you start:** a backup holds every carrier's gateway and SIP credentials.
Restoring the production backup on another server that is online makes that
server register to the real carriers, and it can take registrations away from
production. To practise, restore the test server's own backup, or use check 1d,
which never touches FreeSWITCH.

### 1a. Get a server (skip this to restore in place)

1. Build a new server with the same OS. Install Junction (README → Install), at
   the same version as the backup or a newer one. The backup's `VERSION` file
   says which version made it.
2. Use the same hostname, so certificates and carrier settings still match.
   Point DNS at it once the restore is done.

### 1b. Restore the database

1. Copy the newest backup to the server. Backups are in Settings → Backups, in
   `/var/www/html/storage/app/private/backups/` on the old server, or in your
   SFTP or S3 storage. Note the time you start.
2. Unpack it, stop the workers and restore it. These are the same steps that
   are in `README.txt` inside every backup:
   ```sh
   mkdir restore && cd restore
   tar -xf softswitch-backup-….tar
   # encrypted: openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in softswitch-backup-….tar.enc | tar -xf -
   systemctl stop softswitch-queue softswitch-scheduler
   runuser -u postgres -- pg_restore --clean --if-exists --no-owner --role=softswitch -d softswitch database.dump
   ```
3. **On a new server only:** put the old app key in place, so the restored
   passwords can be read. It is `app-key.txt` in an encrypted backup, or
   `APP_KEY` in the old server's `/var/www/html/.env`. Without the key, every
   carrier and SMTP password has to be typed in again.
   ```sh
   sed -i "s|^APP_KEY=.*|$(cat app-key.txt)|" /var/www/html/.env
   cd /var/www/html && php artisan config:cache
   ```
4. Bring the database up to this version's schema and clear what the old
   server cached:
   ```sh
   app$ php artisan migrate --force
   app$ php artisan cache:clear
   ```

### 1c. Bring it back up

1. **Check the firewall before applying it.** The restored settings may limit
   SSH or the web UI to the old server's admin addresses:
   ```sh
   app$ php artisan softswitch:firewall status
   ```
   If SSH is "locked to the whitelist" and your address isn't on the list,
   unlock it first: `app$ php artisan softswitch:firewall unlock --service=ssh`.
2. Write the FreeSWITCH gateways, the firewall and the PBX users from the
   restored database, then start the workers:
   ```sh
   app$ php artisan softswitch:sync
   systemctl start softswitch-queue softswitch-scheduler
   ```
3. Check it works: `app$ php artisan softswitch:selftest` places real test
   calls. Sign in to the admin UI, check that the carriers' gateways show as
   registered, and look at the newest CDRs.
4. Point DNS at the server if it's a new one. Note the time: that's your RTO.
5. **A new server only:** in Settings → Certificates, issue the Let's Encrypt
   certificate again once DNS points here, and set up Settings → Backups again
   (the off-site copy settings came back with the database; check they work
   with **Run now**).

### 1d. Check a backup without using it

This proves a production backup can be read and is complete, without touching
the switch it's on. Run it anywhere PostgreSQL is installed, on a copy of the
file:

```sh
mkdir check && cd check && tar -xf softswitch-backup-….tar   # or the openssl line above
cat VERSION
pg_restore --list database.dump | grep -c "TABLE DATA"       # dozens, not zero
runuser -u postgres -- createdb softswitch_check
runuser -u postgres -- pg_restore --no-owner -d softswitch_check database.dump
runuser -u postgres -- psql -d softswitch_check -c "select count(*), max(started_at) from cdrs"
runuser -u postgres -- dropdb softswitch_check               # a scratch copy, not the live CDRs
```

`max(started_at)` should be within an hour of when the backup was made.

---

## 2. The main server is down, and there is a backup server (HA)

1. Make sure the main server really is down, or cut it off. Two main servers
   both taking calls is worse than a few minutes' outage.
2. On the backup server: `softswitch-ha status` should say it is a standby and
   how far behind it is. Then click **Make this the main server** in the
   backup's admin UI (`https://<backup's IP>`), or run:
   ```sh
   softswitch-ha promote
   ```
3. Point DNS (and any carrier IP allowlists that name the main server's address)
   at the backup server.
4. Run `app$ php artisan softswitch:selftest`, then check that calls and CDRs
   are arriving in the admin UI.
5. Once the old main server is repaired, make it the new backup:
   `softswitch-ha clone` on it. This **replaces its database**, so copy anything
   off it first.

---

## 3. Locked out by the firewall

Every firewall change made from the admin UI rolls back on its own unless it's
confirmed within a few minutes, so first **wait for the rollback**. That's
normally all it takes.

If you're still locked out:

1. Get a console that doesn't go through the firewall: the hosting provider's
   web console, KVM or rescue mode.
2. See what's pending or locked down, and open it:
   ```sh
   app$ php artisan softswitch:firewall status
   app$ php artisan softswitch:firewall unlock --service=ssh   # or web, pbx; no option = all
   softswitch-firewall rollback                                # drops a change still waiting for confirmation
   ```
3. Add your address in Settings → Firewall before you lock anything down again.

fail2ban can also ban you after failed logins. Check with
`fail2ban-client status sshd` and remove the ban with
`fail2ban-client set sshd unbanip <your address>`.

---

## 4. The certificate has expired

Browsers refuse the admin UI, carriers on TLS fail, and WebRTC phones can't connect.

1. See what's in place: `softswitch-cert status`.
2. Renewal runs from `certbot-renew.timer`. Check that it's on and see why it
   failed:
   ```sh
   systemctl status certbot-renew.timer
   journalctl -u certbot-renew --since -7d
   ```
   The usual causes: DNS no longer points here, or port 80 is closed
   (Let's Encrypt checks over HTTP).
3. Fix the cause, then renew it from Settings → Certificates, or run:
   ```sh
   softswitch-cert issue <hostname> <email>
   ```
4. If you need the UI back before that, go back to the self-signed certificate:
   `softswitch-cert selfsigned`. Browsers warn, but it works.

---

## 5. FreeSWITCH won't start

No calls are flowing, and the admin UI shows the switch as down.

1. Find out why:
   ```sh
   systemctl status freeswitch
   journalctl -u freeswitch -n 100
   tail -n 200 /usr/local/freeswitch/var/log/freeswitch/freeswitch.log
   ```
2. Common causes:
   - **An XML error in a config file.** The log names the file and line. Gateway
     and PBX files are rewritten from the database, so after fixing the bad
     data in the UI, run `app$ php artisan softswitch:sync --gateways`
     (or `--pbx`).
   - **The port is already in use**, by a second FreeSWITCH or a leftover
     process: `ss -lunp | grep -E ':(5060|5080)\b'` (PBX and carrier ports).
   - **It can't reach the database** (`route.lua` errors): check
     `systemctl status postgresql`.
   - **The disk is full**: `df -h`. Old logs and captures can go; CDRs are never
     deleted.
3. Start it with `systemctl start freeswitch`. Then run
   `app$ php artisan softswitch:selftest`.
4. If a recent update broke it, the installer can rewrite the FreeSWITCH config:
   `cd ~/Junction && ./install.sh --yes --steps 60`.

---

## Drill log

| Date | Runbook | What was restored | Time taken | Notes |
| --- | --- | --- | --- | --- |
