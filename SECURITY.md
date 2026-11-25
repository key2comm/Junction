# Security policy

## Reporting a vulnerability

Please report security problems privately, by email to **seank@key2.ca**, not in a public issue or pull request.

Include what you found, the Junction version (shown in the admin UI's footer, or in `/var/www/html/VERSION` on the server), and the steps to reproduce it. If you have a fix in mind, describe it in the email.

You'll get a reply within a few working days. Please give us a reasonable time to release a fix before you share details publicly; we'll agree a date with you and credit you in the release notes if you'd like.

## Supported versions

Only the newest release gets security fixes. Update with `cd ~/Junction && sudo ./update.sh`. Releases are signed, and `update.sh` checks the signature before installing (see [README.md](README.md#update)).

## In scope

Junction itself: the installer and its scripts, the admin UI, customer portal and REST API, the call routing, and the configuration it writes for FreeSWITCH, nginx, PHP-FPM, PostgreSQL, the firewall and systemd.

Problems in FreeSWITCH, PostgreSQL, Laravel or other projects Junction uses should go to those projects. Tell us as well if Junction's configuration makes them exploitable.
