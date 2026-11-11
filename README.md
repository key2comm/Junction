# Junction

Class 4 Softswitch based on FreeSWITCH.

An open Class 4 (wholesale carrier) switch: it routes VoIP traffic between customer and vendor carriers using least-cost routing, and includes a few light PBX features.

> Status: **v0.1.0 beta**. Test on your own traffic before relying on it. See [CHANGELOG.md](CHANGELOG.md) for what's included.

## Install

Requirements: Rocky Linux, AlmaLinux, RHEL, or CentOS Stream 9/10. Use a fresh server with a public IP and root/sudo access.

```sh
git clone https://github.com/key2comm/Junction.git
cd Junction
sudo ./install.sh
```
### Admin Dashboard.

The Admin UI is a web dashboard that shows the server's health and call activity at a glance. Open it at `https://<your-server>/<admin-path>` and sign in with an admin account.

<img width="1660" height="1820" alt="image" src="https://github.com/user-attachments/assets/565302ce-6b80-449a-8f8b-622b0e0343ac" />

| Field | Description |
|-------|-------------|
| Hostname | The server's host name |
| IP Address | The server's primary IP address |
| Operating System | OS name and version |
| Uptime | How long since the server last rebooted |
| App Version | The version of this application that is installed |

Live usage for the server's main resources, refreshed every 30 seconds.

- **CPU usage**: current load as a percentage of all cores. The core count is shown under it.
- **RAM usage**: memory used against total memory, for example `3.2 GB / 8 GB (40%)`.
- **Disk space**: space used against total space on `<mount, e.g. />`, with a percentage bar.

### Call Statistics

Call counts come from the call detail records (CDR).

| Card | What it counts |
|------|----------------|
| Calls Today | Calls since midnight in the server's time zone |
| Last 7 Days | Calls in the past 7 days, today included |
| This Month | Calls since the 1st of the current month |


### Customer portal

Customers can sign in at the same address and land in `/portal`. There they see their balance, calls, invoices, rates, and their own API tokens; never vendors or costs. Add portal logins from the customer's account page. If email is set up, they get a link to set their password.

### REST API

Create a token in **Settings → API tokens**, then call `https://<your-server>/api/v1/...` with `Authorization: Bearer ss_…`. The OpenAPI 3.1 spec is at `/api/v1/openapi.json`. Customer-scoped tokens only see that customer's account, calls, and invoices.

```sh
curl -H "Authorization: Bearer $TOKEN" https://switch.example.com/api/v1/cdrs/summary
```

### Carriers

Add your Carriers, Currency, Time Zone,  Billing email, NOC email, and set inbound and outbound limits. Select the codecs you wish to use. Add Carrier rates so you know how much calls are costing you. Strip leading digits or prefix if needed. 

<img width="1140" height="1802" alt="image" src="https://github.com/user-attachments/assets/905e75d2-18ab-4702-9559-0e8c827ecb7e" />

Add your carrier rates. Upload and choose columns. Min needed, Prefix, Destination, Rate per Min and Interval. Delete all rates and start over. Manually add rates as needed. 

<img width="1100" height="804" alt="image" src="https://github.com/user-attachments/assets/6ace45ab-78b1-4276-a017-ea53033dae2d" />

Build tariffs off of your carrier rates by markup or by a fixed amount per min. Assign different rates to different customers. Manually add rates in. 


<img width="1636" height="1172" alt="image" src="https://github.com/user-attachments/assets/25caba0b-244d-46db-ab87-5c64a1492e46" />

