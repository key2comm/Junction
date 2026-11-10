# Softswitch

An open Class 4 (wholesale carrier) softswitch built on FreeSWITCH. It routes VoIP traffic between customer and vendor carriers using least-cost routing, and includes a few light PBX features.

> Status: early development. Not ready for production traffic.

## Install

Requirements: Rocky Linux, AlmaLinux, RHEL, or CentOS Stream 9/10. Use a fresh server with a public IP and root/sudo access.

```sh
git clone https://github.com/key2comm/Softswitch.git
cd Softswitch
sudo ./install.sh
```
### Customer portal

Customers can sign in at the same address and land in `/portal`. There they see their balance, calls, invoices, rates, and their own API tokens; never vendors or costs. Add portal logins from the customer's account page. If email is set up, they get a link to set their password.

### REST API

Create a token in **Settings → API tokens**, then call `https://<your-server>/api/v1/...` with `Authorization: Bearer ss_…`. The OpenAPI 3.1 spec is at `/api/v1/openapi.json`. Customer-scoped tokens only see that customer's account, calls, and invoices.

```sh
curl -H "Authorization: Bearer $TOKEN" https://switch.example.com/api/v1/cdrs/summary
```

### Carriers

Add your Carriers, Currency, Time Zone,  Billing email, NOC email, set inbound and outbound limits. Select the codecs you wish to use. Add Carrier rates so you know how much calls are costing you. Strip leading digits or prefix if needed. 

<img width="1140" height="1802" alt="image" src="https://github.com/user-attachments/assets/905e75d2-18ab-4702-9559-0e8c827ecb7e" />

Add your carrier rates. Upload and choose columns. Min needed, Prefix, Destination, Rate per Min and Interval. Delete all rates and start over. Manually add rates as needed. 

<img width="1100" height="804" alt="image" src="https://github.com/user-attachments/assets/6ace45ab-78b1-4276-a017-ea53033dae2d" />

Build tariffs off of your carrier rates by markup or by a fixed amount per min. Assign different rates to different customers. Manually add rates in. 


<img width="1636" height="1172" alt="image" src="https://github.com/user-attachments/assets/25caba0b-244d-46db-ab87-5c64a1492e46" />

