# RadiusOne user guide

What each page of the portal does, how to set it up and how it works, for the operators who run it.
Installing the server is in [INSTALL.md](../INSTALL.md).

Page names below are as they appear in the portal's navigation: **Page → tab**. In the portal, this
guide is under **Documentation** (superadmins only). Examples use placeholder addresses and IDs
(192.0.2.x, example.com, 123456789), never a real server's settings.

## Contents

1. [Getting started](#1-getting-started)
2. [Dashboard](#2-dashboard)
3. [Clients](#3-clients)
4. [Services and IP pools](#4-services-and-ip-pools)
5. [NAS devices](#5-nas-devices)
6. [Monitoring](#6-monitoring)
7. [Network operations](#7-network-operations)
8. [Billing](#8-billing)
9. [Reports and logs](#9-reports-and-logs)
10. [Your profile](#10-your-profile)
11. [Settings: operators](#11-settings-operators)
12. [Settings: email and SMS gateways](#12-settings-email-and-sms-gateways)
13. [Settings: customer messages](#13-settings-customer-messages)
14. [Settings: admin alerts and daily report](#14-settings-admin-alerts-and-daily-report)
15. [Settings: branding](#15-settings-branding)
16. [Settings: backups](#16-settings-backups)
17. [Settings: data retention](#17-settings-data-retention)
18. [Server operations](#18-server-operations)
19. [Troubleshooting](#19-troubleshooting)

---

## 1. Getting started

RadiusOne is the operator portal for the FreeRADIUS server: customers and their internet plans, the
routers (NAS) they connect through, billing, monitoring, and messages to customers and to your team.
Everything a page changes is stored in the same database FreeRADIUS reads, so most changes apply to
the next customer login.

### Signing in

1. Open the portal and sign in with your operator username and password.
2. If two-factor authentication is on for your account, enter the 6-digit code from your
   authenticator app. If an administrator made it mandatory, you are asked to set it up on your first
   sign-in.

### Staying signed in

Once you are signed in (password, plus the 6-digit code when two-factor is on), the portal does not
ask for your password again for sensitive actions such as saving a NAS or gateway, revealing a
customer password or restarting services. Sign out when you leave a shared computer: anyone at an
open session can do what your role allows.

### Roles

| Role | What it can do |
|---|---|
| superadmin | Everything, including Settings, operators, backups, service restarts and this documentation. |
| admin | Clients, services, NAS, billing, reports and logs. No Settings. |
| noc | Provision services, manage NAS and review network operations. No payments. |
| billing | Invoices and payments. Cannot lift manual or security restrictions. |
| security | Apply and lift security restrictions, read logs. No plan or payment changes. |
| support | Read-only connection diagnostics. |
| view | Read-only access to clients, services, NAS, billing, reports and logs. |

Every change is written to the audit log ([Logs](/logs)) with the operator who made it.

## 2. Dashboard

The first page after sign-in. It shows the key figures for your network, the status of each NAS,
recent authentication rejects and the top users this month. The figures refresh while the page is
open.

Use the rejects list to spot customers with a wrong password or an expired service before they call.

## 3. Clients

Each client is one internet account (a PPPoE username) with contact details, a service plan, an end
date and optional restrictions.

### Add a client

1. Open [Clients](/clients) and choose **New client**.
2. **Identity and contact**: name, phone and email. Phone and email are used for alerts and
   invoices; tick **SMS alert** / **Email alert** only if the customer agreed to messages.
3. **Credentials**: the PPPoE username and password the router uses to log in.
4. **Service and status**: the plan, the service end date and, only when needed, a static IP pool
   and address.
5. **Access restrictions** (optional): lock the account to one NAS or one MAC address.
6. Save. The account can connect immediately.

### Working with a client

- **Overview**: current session, recent sessions, usage per day, invoices and payments.
- **Diagnostics**: why a customer cannot connect: account state, plan, last login attempts and
  replies, open sessions and queued router changes.
- **Disconnect** ends the current session; the customer can reconnect straight away. **Suspend**
  blocks the account until the restriction is lifted. A payment never clears a manual or security
  suspension.
- **Reveal saved password** shows the PPPoE password once; each reveal is recorded in the audit log.
- **Documents**: contracts and IDs attached to the customer; they are included in backups.
- From the list you can search, export to CSV and apply bulk actions to selected clients.

> When a service end date passes, the expiry job moves the customer to the plan's *expired* group, so
> the router gives them the expired-service behavior (for example a payment page) instead of normal
> access.

## 4. Services and IP pools

A service plan is a RADIUS group: speed, price, billing mode and what happens after expiry. Clients
on the plan receive its attributes when they log in.

1. Open [Services](/services) → **New plan**.
2. Set the name, speed and price, and choose the **expired service** group customers move to when
   their end date passes.
3. Use **Custom group attributes** only for vendor-specific settings the plan fields do not cover.

### Static IP pools

Under [Static IP pools](/services/ip-pools), list the addresses you sell, one per line, as ranges
(`192.0.2.10-20`) or small CIDRs (`192.0.2.0/28`), with a monthly price. A client form then offers
only the free addresses of the chosen pool.

A plan or pool that clients still use cannot be deleted; move or archive the clients first.

## 5. NAS devices

A NAS is a router (BRAS) that sends customer logins to RADIUS. RADIUS only answers NAS devices listed
here.

**MikroTik RouterOS** is tested end to end (login, IP, speed limits, disconnect). **Cisco IOS XE** is
marked **Beta**: it follows Cisco's documentation but has not been tested on a real router yet, so
check logins and disconnects carefully before relying on it.

### Add a NAS

1. Open [NAS Devices](/nas) → **New NAS device**.
2. Enter a name, the router's IP address as RADIUS sees it (for example `192.0.2.1`), its platform
   and the **RADIUS shared secret**. Use the same secret in the router's RADIUS client settings.
3. Leave **Disconnect sessions with RADIUS (CoA)** on (port 3799 for MikroTik, often 1700 for Cisco).
4. Save, then open the NAS again for the optional **API** and **SNMP monitoring**.

### Disconnecting sessions

The **Disconnect** button on a customer sends a RADIUS **Disconnect-Request (CoA)** to the router,
signed with the shared secret, and reports the router's answer straight away. No API login is
needed. On MikroTik, allow it once:

```
/radius incoming set accept=yes port=3799
```

RouterOS accepts disconnects only from the RADIUS servers listed under `/radius`, with the right
secret.

On MikroTik you can instead switch on the **API** tab (a dedicated RouterOS user with read, write and
api rights, over api-ssl on port 8729 with a certificate this server trusts). While the API is on,
disconnects use it instead of CoA.

### "Waiting for FreeRADIUS restart"

Changing a NAS address, name, type, shared secret or switching it on or off raises its *config
revision*. FreeRADIUS reads the NAS list only when it starts, so such a change takes effect after a
FreeRADIUS restart ([Monitoring → RADIUS server → Restart](#restarting-a-service)). Until then the NAS
shows **waiting for FreeRADIUS restart** and the portal does not send disconnects or speed changes to
it.

Within a minute after FreeRADIUS has started again, the portal records the change as in effect by
itself (FreeRADIUS does not start with a broken NAS list). CoA, API and SNMP settings are used by the
portal alone and apply at once, without a restart.

### SNMP

With SNMP (v2c read-only community, or v3 with authentication and optional AES privacy), the poller
records CPU, memory, temperature and interface traffic every minute for the Monitoring graphs. Only
read requests are sent. Without SNMP the NAS is checked by ping.

## 6. Monitoring

[Monitoring](/monitoring) shows the RADIUS server and every NAS on one page, refreshing by itself.
Red values need attention: CPU above 90%, memory above 95%, disk above 90%, a NAS that stopped
answering, or a stuck background job.

### Network tables

On a NAS with SNMP set up, its monitor page (**Monitor** on its card) has **Network tables**: IP
addresses, ARP, MAC table, BGP peers and OSPF neighbours. Each is read live from the router over SNMP
(read-only) when you open its tab; **Refresh** reads it again, the filter narrows the rows, and the
table can be exported as CSV. A device that does not publish a table over SNMP shows it empty with a
note (for example, RouterOS may not publish OSPF).

### RADIUS server page

- **Services**: FreeRADIUS, MariaDB, Nginx, PHP-FPM and Cron, each with its state and how long it has
  been running (*up 4d 6h 37m*). The time restarts from zero after every restart.
- **System**: uptime, load, memory, disk and temperatures (a virtual machine usually has no sensors).
- **History**: CPU, memory and traffic graphs by time range.

### Restarting a service

Superadmins see a **Restart** button next to FreeRADIUS, MariaDB, Nginx and PHP-FPM.

- The configuration is checked first; if the check fails nothing is restarted and the error is shown,
  so a typo cannot leave the service down.
- The restart starts two seconds after you confirm, so the page can answer first. The status updates
  within about 15 seconds.
- FreeRADIUS: logins pause for a few seconds; connected customers normally stay online. MariaDB: the
  portal and RADIUS logins fail for a few seconds (you type `restart` to confirm). Nginx / PHP-FPM:
  the portal is unreachable for a few seconds.
- Every restart and failed attempt is written to the audit log.

> The installer (`install.sh`) sets this up: a small helper and a sudo rule that let the web server
> restart exactly these four services and nothing else. To set it up again by hand, as root:
> `sudo bash ops/apply-service-control.sh` from the app directory.

### NAS monitor

Each NAS has its own page with live health, the busiest interfaces, online sessions, history graphs
and the SNMP OIDs used for its platform. **Poll now** records a sample immediately instead of waiting
for the next minute.

### Background jobs

The bottom of Monitoring shows whether each scheduled job is running. A job that is not running
leaves its feature switched off. See [Server operations](#18-server-operations) for the schedule.

## 7. Network operations

[Network operations](/operations) lists the latest router changes the portal queued, for example
speed changes after a plan change, or disconnects. Each shows its state and the router's answer.

**Reconcile / retry** sends a failed change again after you fixed the cause (router unreachable,
wrong API login). Changes are sent by the network worker; while it is not enabled, they wait in the
queue and Monitoring shows the worker as not sending.

## 8. Billing

[Billing](/billing) holds invoices and payments. The cards at the top show what is still to collect,
what is overdue and what came in this month; click one to see those invoices. The tabs above the list
filter by status, with a count on each.

1. **New invoice**: choose the customer, the service plan (or no internet charge for static-IP-only
   invoices), the optional static IP add-on, the billing period and the issue date (discount, tax and
   notes are under the fold). The summary on the right shows the total and the due date; click
   **Issue invoice** to bill the customer, or **Save as draft** to finish it later.
2. Use **Record payment** on an unpaid invoice (or open it): amount, date, method, reference. Full
   payment of a renewal extends the service end date.
3. A draft can be deleted; an issued invoice can only be cancelled, so the numbering stays complete.

**Recurring invoices** are created every night at 03:15 (server time) for customers whose period is
due, and overdue invoices are marked. Invoices and payment receipts can be downloaded as PDF, with
your uploaded logo.

## 9. Reports and logs

- [Reports](/reports): accounting sessions started in a chosen range, open sessions, and session
  totals by start date.
- [Logs](/logs): the **authentication attempts** (every accepted and rejected login, filterable by
  reply) and the **admin audit log** (who changed what, and when).

How long login and session records are kept is set under
[Data retention](#17-settings-data-retention).

## 10. Your profile

Click your name at the top to open [your profile](/profile): name, email, language, password (at
least 10 characters) and two-factor authentication.

1. **Set up authenticator**, then scan the QR code with an authenticator app (Google Authenticator,
   Microsoft Authenticator, 1Password and similar).
2. Enter the current 6-digit code to confirm. From then on, sign-in asks for a code.

## 11. Settings: operators

Under [Settings → Users](/settings?tab=users), superadmins add operators, choose their role, disable
accounts and turn on mandatory two-factor authentication. A disabled operator cannot sign in; their
history stays in the audit log. Give each person their own account and the smallest role that fits
their work.

## 12. Settings: email and SMS gateways

Customer messages and admin alerts are sent through the gateways under
[Settings → Notifications → Gateways](/settings?tab=notifications&view=gateways).

### Email

| Provider | What you need |
|---|---|
| Custom SMTP | Host, port, encryption (TLS/SSL), username and password from your mail provider. |
| Mailgun | Sending domain, API key and region (US or EU). |

Set a **from email** your provider allows (for example `noc@example.com`). Emails go out under your
company name from *Settings → Branding* (RadiusOne when none is set); invoice and receipt PDFs and the
brand name in SMS texts use the same name.

### SMS

| Provider | What you need |
|---|---|
| Twilio | Account SID, auth token and a sender number or messaging service SID. |
| Infobip | Your base URL, an API key (portal → Developer tools → API keys) and a registered sender. |
| Custom HTTP | The provider URL with `{phone}` and `{message}` placeholders (or a form body for POST), and optionally the text a successful answer contains. |

The **default country code** turns local numbers into international ones (for example `0691234567`
becomes `+355691234567`). Leave it blank if every saved number already has its country code.

After saving, use **Send test** on each gateway. "Accepted" means the provider took the message;
check that it arrives.

> Gateway passwords, tokens and keys are stored encrypted and are never shown again. Leave a password
> field empty to keep the saved value.

## 13. Settings: customer messages

- **Templates**: *Welcome* (sent once after a new customer's first verified activation), *Service
  extended* (sent when a saved renewal moves the end date forward) and *Custom* (your starting point
  for bulk messages). Turn on Email and/or SMS per template.
- Personalize with `{name}`, `{username}`, `{service}`, `{expiry}`, `{app_name}`, `{phone}` and
  `{email}`.
- **Send bulk message**: choose Email or SMS, select customers (or all active customers, up to
  1,000), write the message and **review** it. Nothing is sent until you confirm the preview; the
  preview expires after 30 minutes.
- **History**: every message with its status. "Uncertain" needs a check with the provider and is not
  retried automatically.

Customers whose Email or SMS alert flag is off never receive messages on that channel. Queued
messages are sent every minute.

## 14. Settings: admin alerts and daily report

Admin alerts tell your team when something on the server or network needs attention, and send a
daily report. Set them up under
[Settings → Notifications → Admin alerts](/settings?tab=notifications&view=admin).

### Set up

1. Set up the Email gateway (and SMS, if you want text alerts). Telegram is set up on the same page,
   see below.
2. Tick **Send admin alerts and reports** and add the recipients: email addresses and/or phone
   numbers, separated by commas.
3. Choose what to watch and the channels for alerts.
4. Optionally turn on the **daily report** and its time (server time).
5. Save, then press **Send test alert** and **Send report now**.

### How alerts work

| Check | Alerts when |
|---|---|
| Server services | FreeRADIUS, MariaDB, Nginx, PHP-FPM or Cron is not running. |
| NAS | A NAS stops answering ping, or its SNMP data is older than the stale limit. NAS devices switched off for RADIUS are ignored. |
| Resources | Disk / is 90% full or more, or memory is 95% used or more. |
| Background jobs | A job is stuck (for example router changes waiting with nothing being sent). |

- Checks run every minute. A problem must still be there on the next check before it alerts, so a
  restart does not wake anyone.
- When it clears you get a **RESOLVED** message with how long it lasted.
- One message per check lists every new and resolved problem, plus those still open.
- **Recent alerts** shows the last 30 alerts, reports and tests with who received them and any
  provider error. One failing recipient does not stop the others.

> **Warning:** if MariaDB itself is down, the alert job cannot read its settings and no alert is sent.
> You will see it on Monitoring, and in the next report once the database is back.

### Daily report

Sent by email (and Telegram when chosen, never by SMS) at the chosen time (server time): open
problems, server uptime and load, each service with its uptime, RADIUS logins accepted/rejected in the
last 24 hours, every NAS with status and online users, customers (total, active, expired, expiring
within 7 days), background jobs, and the alerts of the last 24 hours.

### Telegram

Telegram is used only for admin alerts and the report, never for customer messages.

1. In Telegram, open **@BotFather**, send `/newbot`, choose a name and a username ending in *bot*,
   and copy the token it gives you (it looks like `123456789:ABC…`).
2. Send any message to your new bot. For a team group, add the bot to the group and write a message
   there. For a channel, add the bot as an administrator.
3. In **Admin alerts → Telegram**, paste the token and save. The token is stored encrypted and never
   shown again.
4. Press **Find chat IDs**. It lists the chats that wrote to the bot, for example
   `123456789 · Name (private)` or `-1001234567890 · Team (group)`.
5. Copy the numbers into **Telegram chat IDs** (separated by commas; `@channelname` also works for
   public channels).
6. Tick **Telegram** under *Send alerts by*, and optionally **Also send the daily report to
   Telegram**. Save and press **Send test alert**.

If a test fails, Recent alerts shows Telegram's reason: *chat not found* (wrong ID, or nobody wrote to
the bot yet), *bot was blocked by the user*, or *Unauthorized* (wrong or revoked token; create a new
one with `/token` in @BotFather). To stop Telegram, untick it, or tick **Remove the saved bot
token**.

## 15. Settings: branding

Under [Settings → Branding](/settings?tab=branding), upload a PNG, JPEG or WebP logo (up to 2 MB) or
link one hosted on your website over HTTPS. The logo appears in the sidebar and on the sign-in page.
Only an uploaded logo also appears on PDF invoices.

## 16. Settings: backups

Every night at 02:15 the database and customer documents are backed up on the server, encrypted, and
uploaded to each enabled destination under [Settings → Backups](/settings?tab=backups).

1. Set the **backup passphrase** (at least 12 characters). Store it somewhere safe outside this
   server: without it no backup can be opened. Changing it affects only new backups.
2. Choose how long **local copies** stay on the server. The newest three of each kind are always
   kept.
3. Add destinations: [Backblaze B2](#backblaze-b2-setup), [Cloudflare R2](#cloudflare-r2-setup) (the
   bucket address and a key; for Backblaze the key alone; guides below), or **My own server (SFTP)**
   as `user@host` (add the shown public key to its authorized keys). Name, folder and copies to keep
   (default 7) are under **More options**. Then press **Test**.
4. The page shows the last run and the result per destination.

### Backblaze B2 setup

About 10 minutes. 10 GB is free, then about 6 USD per TB per month. No app registration or approval
is needed.

1. Create an account at [backblaze.com](https://www.backblaze.com/sign-up/cloud-storage) (B2 Cloud
   Storage) and choose a region close to you, for example *EU Central*.
2. **Create a bucket**: *B2 Cloud Storage → Buckets → Create a Bucket*. Give it a unique name (for
   example `isp-name-radius-backups`), set **Files in Bucket** to **Private**, leave encryption and
   object lock off, and create it.
3. **Create a key**: *Application Keys → Add a New Application Key*. Name it (for example
   *radiusone*), allow access to **this bucket only**, type of access **Read and Write**, and create
   it.
4. **Copy both values now**: **keyID** and **applicationKey**. The applicationKey is shown only once.
5. **In the portal**: *Settings → Backups → Add destination*, **Store backups in** **Backblaze B2**.
   Paste the **keyID** and **applicationKey** and save: the bucket and its endpoint are found from the
   key. (If the key can reach several buckets, also paste the bucket address, for example
   `https://s3.eu-central-003.backblazeb2.com/my-bucket`.)
6. Press **Test**. It uploads, lists and deletes a small file.

> B2 keeps old versions of a file by default. The portal deletes its own old backups by name, so set
> the bucket's **Lifecycle Settings** to *Keep only the last version of the file*; otherwise deleted
> copies stay billable as hidden versions.

### Cloudflare R2 setup

About 10 minutes. 10 GB is free and downloads (restores) are always free. A payment method is needed
to enable R2, even on the free tier.

1. Sign in at [dash.cloudflare.com](https://dash.cloudflare.com/) (a free account is enough) and open
   **R2 Object Storage**. Enable it the first time.
2. **Create a bucket**: *R2 → Create bucket*. Name it (for example `radius-backups`), location
   *Automatic* (or a jurisdiction such as EU), storage class *Standard*, and create it. Leave public
   access off.
3. **Copy the bucket address**: in the bucket, *Settings → S3 API* shows
   `https://<account id>.r2.cloudflarestorage.com/<bucket>`. Copy it whole.
4. **Create a token**: *R2 → Manage API tokens → Create API token* (an *Account* token). Permission
   **Object Read & Write**, apply to **this bucket only**, no expiry, and create it.
5. **Copy both values now**: **Access Key ID** and **Secret Access Key** (shown only once). The other
   token value is not needed.
6. **In the portal**: *Settings → Backups → Add destination*, **Store backups in** **Cloudflare R2**.
   Paste the bucket address and both keys, and save.
7. Press **Test**.

### Backup storage problems

| What you see | Cause and fix |
|---|---|
| InvalidAccessKeyId or SignatureDoesNotMatch | The access key ID or secret is wrong, or was deleted at the provider. Paste a current pair (the secret field keeps the saved one when left empty). |
| AccessDenied | The key is limited to another bucket, or is read-only. Give it read and write access to this bucket. |
| NoSuchBucket | The bucket address does not match the bucket (wrong name, region or account). Paste it again from the provider. |
| Could not connect / timed out | Check the bucket address and that this server can reach the internet on port 443. |
| Storage keeps growing on B2 | Set Lifecycle Settings to keep only the last version (see the note above). |

### On the server

- Run a backup now: `sudo FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env php artisan frweb:backup`
- Open a downloaded copy: `php artisan frweb:backup-decrypt <file.enc>` (asks for the passphrase).
- Restore the database: `php artisan frweb:restore <file.sql.gz>`. This replaces the current data;
  take a fresh backup first.
- The nightly job (02:15, as root) is in `/etc/cron.d/radiusone`, set up by the installer.

## 17. Settings: data retention

Under [Settings → Data retention](/settings?tab=retention), choose how long RADIUS session records
(accounting) and login records are kept. Older records are deleted by the nightly cleanup at 03:45.
The default keeps everything. Shorter periods keep the database fast, but reports cannot look further
back than the period.

## 18. Server operations

One cron line runs the Laravel scheduler every minute as the web user. Each job keeps its own lock,
so a slow run is never started twice. Logs are in `/var/log/freeradius-web/`.

| Job | When | What it does |
|---|---|---|
| `frweb:poll-snmp` | Every minute | Records server and NAS samples for Monitoring. |
| `frweb:admin-alerts` | Every minute | Admin alerts and the daily report (off until enabled in Settings). |
| `frweb:process-customer-notifications` | Every minute | Sends queued customer email and SMS. |
| `frweb:sync-expired` | Every minute (`FRWEB_SCHEDULE_EXPIRY_SYNC=true`) | Expires services, moves expired customers to their expired plan and publishes changed access rules to RADIUS. |
| `frweb:process-network-operations` | Every minute (`NETWORK_WORKER_ENABLED=true`) | Applies those changes on the network: an online customer is disconnected (CoA or RouterOS API) so they reconnect with the new rules; an offline one gets them at the next login. A refused disconnect is retried five times, then shown under Network operations. |
| `frweb:mark-nas-applied --auto` | Every minute | Records NAS changes as in effect once FreeRADIUS has restarted. |
| `frweb:generate-billing` | 03:15 | Creates recurring invoices and marks overdue ones. |
| `frweb:backup` | 02:15 (root cron) | Database and documents backup with encrypted remote copies. |
| `frweb:prune-job-runs` | 03:30 | Removes old job history. |
| `frweb:prune-radius` | 03:45 | Applies the data retention periods. |

### Useful commands

Run from the app directory.

| Command | Use |
|---|---|
| `php artisan frweb:job-status` | Recent job runs, schema state, network lag and the notification queue. |
| `php artisan frweb:check-schema` | Lists database migrations a feature still needs. |
| `php artisan frweb:migrate` | Applies pending database migrations. |
| `php artisan frweb:mark-nas-applied` | Records that a NAS change is active in FreeRADIUS (see NAS devices). |
| `php artisan frweb:encrypt-secrets` | Encrypts or re-keys stored credentials. |
| `php artisan schedule:list` | Shows every scheduled job and when it runs next. |

### Installing and setup

- `sudo bash install.sh`: installs and configures everything on a fresh Ubuntu 24.04 server (see
  [INSTALL.md](../INSTALL.md)). Running it again repairs the setup and keeps all data.
- `sudo bash ops/apply-service-control.sh`: re-installs only the helpers behind the Restart, time
  zone, Back up now and Software update buttons.
- **Updates**: *Settings → Overview → Software update* checks GitHub for a newer release and installs
  it (database backup first, a few minutes of maintenance, automatic roll-back if a step fails). Log:
  `/var/log/freeradius-web/update.log`.

## 19. Troubleshooting

| Symptom | What to check |
|---|---|
| A customer cannot connect | Clients → the customer → Diagnostics: account state, end date, last login replies. Then Logs → Authentication attempts for that username. |
| All customers on one router fail | Monitoring: is the NAS answering? Does its shared secret match the router? Is the NAS waiting for a RADIUS update (restart FreeRADIUS)? |
| A NAS shows "waiting for FreeRADIUS restart" | Restart FreeRADIUS (Monitoring → RADIUS server); the NAS shows Up to date within a minute. |
| Disconnect fails or times out | NAS → Device: CoA on and the right port; on MikroTik `/radius incoming set accept=yes`; the shared secret matches; the router clock is correct. With the API on: the API user and certificate. |
| Speed changes do nothing | Network operations: the router's answer. |
| Restart button says restarts are not enabled | Run `sudo bash ops/apply-service-control.sh` from the app directory (`install.sh` does this). |
| Messages are not arriving | Gateways: send a test. History / Recent alerts: the provider's reason. |
| Monitoring graphs are empty | Background jobs: is the Monitoring poller running? Is SNMP set up on the NAS? |
