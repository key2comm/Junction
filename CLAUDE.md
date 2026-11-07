# Softswitch

This project is worked on from multiple devices and synced through GitHub.
- Run `git pull` at the start of a session.
- Commit and push finished work so the other devices can pick it up.
- Never commit secrets. Keep machine-specific config in `.env` or `.claude/settings.local.json` (both gitignored).

## Project
Class 4 softswitch on FreeSWITCH with light PBX features. See `docs/PLAN.md` for the phases and port layout.
- Server: SSH alias `theswitch` (rocky@theswitch.key2pbx.ca), Rocky 10.2, passwordless sudo.
- `deploy/` mirrors where files go on the server (`deploy/nftables/` → `/etc/nftables/`, etc.). Edit here, commit, then push to the server.
- Never commit the SignalWire token or any SIP/ESL/DB passwords.
- Firewall changes must be applied with an automatic rollback timer so a mistake can't lock us out of SSH.
