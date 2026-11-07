# Softswitch build plan

Class 4 softswitch (carrier/trunk routing) with light PBX features, on FreeSWITCH.

## Server

| | |
|---|---|
| Host | `theswitch.key2pbx.ca` (SSH alias `theswitch`, user `rocky`) |
| Public IP | 147.182.148.63 (DigitalOcean droplet), VPC 10.137.0.15 |
| OS | Rocky Linux 10.2 |
| Size | 1 vCPU, 1.7 GB RAM, 49 GB disk |

## Port layout

| Port | Purpose | Who can reach it |
|---|---|---|
| 22/tcp | SSH (keys only) | anywhere, rate-limited and covered by fail2ban |
| 80, 443/tcp | nginx: Let's Encrypt, admin UI, WSS proxy | anywhere |
| 5060/udp+tcp, 5061/tls | FreeSWITCH `internal` profile: phones and customers, digest auth | anywhere, covered by fail2ban |
| 5080/udp+tcp | FreeSWITCH `external` profile: carrier trunks, IP-authenticated | **only IPs in the `carriers` set** |
| 7443 | FreeSWITCH WSS, proxied by nginx `/ws` | localhost only |
| 16384-32768/udp | RTP media | anywhere |
| 9090 | Cockpit | closed |

## Phases

1. **Harden the host:** nftables firewall, fail2ban (sshd now, SIP after phase 2), SSH key-only.
2. **FreeSWITCH:** install from the SignalWire EL10 repo (needs a SignalWire personal access token, which is never stored in git). Strip the demo config. Bind the profiles as in the port layout. Set up an event socket on localhost with a strong password.
3. **Class 4 routing:** carrier gateways, customer trunks (IP or registration auth), and a database for LCR/routing tables (PostgreSQL, called through mod_lcr or Lua), plus CDRs into PostgreSQL. Toll-fraud controls: per-customer channel and CPS limits, and blocked destination ranges.
4. **PBX features:** extensions, voicemail, simple IVR and ring groups.
5. **nginx:** Let's Encrypt for `theswitch.key2pbx.ca`, a WSS proxy for WebRTC, and the admin UI.
6. **Admin UI:** manage carriers, customers, routes, DIDs, and CDRs.

## Deploy model

The `deploy/` folder mirrors where files go on the server (for example `deploy/nftables/` → `/etc/nftables/`). Changes are made here, committed, then pushed to the server. Don't edit files on the server by hand.
