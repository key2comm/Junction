# Softswitch build plan

A Class 4 softswitch routes and manages high-volume long-distance and international VoIP traffic between wholesale carriers, aggregators, and service providers. This one runs on FreeSWITCH and adds a small set of PBX features on the side.

## Concepts

- **Customer (ingress carrier):** sends us traffic and is billed by the minute. Authenticated by IP, with optional tech-prefix.
- **Vendor (egress carrier):** we send traffic to them and pay their rate deck. Reached through FreeSWITCH gateways.
- **Rate deck:** per-prefix price list. There's one for each vendor (buy) and one for each customer (sell).
- **LCR:** for each call, match the longest prefix against vendor decks, rank by cost (plus quality and priority), then try the vendors in order on failure codes.
- **Controls:** limits on calls per second (CPS) and concurrent channels for each customer and vendor, a margin check (never sell below cost), blocked prefixes, and maximum call duration.
- **Quality stats:** ASR (answer rate), ACD (average call length), and PDD (post-dial delay), tracked per vendor and per route, and used to steer routing.
- **CDRs:** one record for each call attempt, holding both the customer leg and the vendor leg, rated at both buy and sell prices.
- **Topology hiding:** customers and vendors never see each other's IPs or headers. FreeSWITCH, acting as a back-to-back user agent (B2BUA), does this naturally.

## Capacity note

FreeSWITCH relays every call's media (RTP). The current 1 vCPU droplet is for building and testing only, and handles roughly 30–50 calls without transcoding. Production needs a larger server, or several media servers behind a Kamailio SIP proxy. That's revisited after phase 3.

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
| 5080/udp+tcp | FreeSWITCH `carrier` profile: **all wholesale traffic** from customers and vendors, IP-authenticated | **only IPs in the `carriers` set** |
| 5060/udp+tcp, 5061/tls | FreeSWITCH `internal` profile: PBX phones only, digest auth | anywhere, covered by fail2ban |
| 7443 | FreeSWITCH WSS, proxied by nginx `/ws` | localhost only |
| 16384-32768/udp | RTP media | anywhere |
| 9090 | Cockpit | closed |

## Phases

1. **Harden the host:** nftables firewall, fail2ban (sshd now, SIP after phase 2), SSH key-only.
2. **FreeSWITCH:** install from the SignalWire EL10 repo (needs a SignalWire personal access token, which is never stored in git). Strip the demo config. Bind the profiles as in the port layout. Set up an event socket on localhost with a strong password.
3. **Class 4 core:** this is the main work.
   - PostgreSQL schema for carriers (customer or vendor), their IPs, rate decks, routes, and CDRs.
   - Identify the customer from the source IP, then normalize the number to E.164.
   - LCR lookup with longest-prefix match. Vendor failover on 503/486/408-type responses, with limits on how many vendors are tried.
   - CPS and channel limits through `mod_hash`/`limit`. Margin check. Blocked prefixes.
   - CDRs into PostgreSQL with both buy and sell rating.
   - Routing logic in Lua, so routes stay in the database rather than in XML.
4. **Quality and operations:** per-vendor ASR/ACD/PDD, alerts, and rate deck CSV import.
5. **nginx and admin UI:** Let's Encrypt for `theswitch.key2pbx.ca`. Web UI for carriers, IPs, rate decks, routes, CDRs and stats, and live calls.
6. **Minimal PBX:** extensions, voicemail, simple IVR, and WebRTC through nginx WSS. Calls to outside numbers go through the same LCR engine as wholesale traffic.

## Deploy model

The project ships as an installer (`install.sh` plus `installer/steps/`), so anyone can install it on their own EL9/EL10 server. `deploy/` holds the config templates; `@SS_VAR@` placeholders are filled in from `/etc/softswitch/softswitch.conf`. Nothing is hand-edited on servers. Change the repo and re-run the installer.

FreeSWITCH is built from public source (signalwire/freeswitch, freeswitch/sofia-sip, freeswitch/spandsp). No SignalWire token or paid repo is needed.

## Later

- STIR/SHAKEN signing and verification (needed for North American carriers; FreeSWITCH supports it through libstirshaken).
- Support more operating systems (Debian/Ubuntu).
