# RadiusOne

**[Website](https://pingomni.com/radiusone/)** · **[Live demo](https://demo-radiusone.pingomni.com/?r=/login)** (demo / demo) · **[User guide](https://pingomni.com/docs/radiusone/)** · by [PingOmni](https://pingomni.com/)

Client, billing and NAS management for FreeRADIUS: customers and service plans, PPPoE and DHCP access,
invoices and payments, NAS monitoring over SNMP, notifications and encrypted backups.
Built with Laravel 13, Inertia and React. The release number is in `VERSION`; what changed is in [CHANGELOG.md](CHANGELOG.md).

## Install

On a fresh **Ubuntu 24.04** server, as root:

```bash
git clone <this repository> /opt/radiusone
cd /opt/radiusone
sudo bash install.sh
```

Full details (requirements, what gets installed, first steps, connecting routers, ports, updating and
troubleshooting): **[INSTALL.md](INSTALL.md)**.

When it finishes, the script prints the portal address and the first administrator's username and
password. The password is shown only once. At first sign-in you set up two-factor authentication with an
authenticator app.

Options:

| Option | Default | |
| --- | --- | --- |
| `--domain NAME` | the server's first IP | Name or IP the portal is reached on |
| `--email ADDRESS` | none | With a real `--domain`: get a Let's Encrypt certificate (port 80 must be reachable). Without it, a self-signed certificate is used and the browser warns once. |
| `--admin-user NAME` | `admin` | First administrator's username |
| `--admin-email ADDR` | `admin@<domain>` | First administrator's email |
| `--timezone ZONE` | unchanged | Server time zone, e.g. `Europe/Tirane` |

Example: `sudo bash install.sh --domain radius.example.com --email you@example.com --timezone Europe/Tirane`

The script installs nginx (HTTPS), PHP 8.3-FPM, MariaDB, FreeRADIUS with its SQL backend, and Node.js 22 to
build the web pages. It creates the `radius` database with one least-privilege user per role, generates
every key and password, schedules the jobs and the nightly backup, and installs the root helpers behind the
portal's Restart, time zone and Back up now buttons. Running it again is safe: keys, passwords, data and
administrators are kept.

Where things are:

| | |
| --- | --- |
| Keys and database passwords | `/etc/freeradius-web/*.env` (root only; `portal.env` also readable by the web server) |
| Uploaded documents and logos | `/var/lib/freeradius-web/` |
| Backups | `/var/backups/freeradius-web/` (nightly at 02:15; remote copies under Settings → Backups) |
| Logs | `/var/log/freeradius-web/`, and `storage/logs/` in the project folder |
| Scheduled jobs | `/etc/cron.d/radiusone` |

After installing, add your routers under **NAS**, then restart FreeRADIUS from **Monitoring → RADIUS
server** so it loads them. RADIUS listens on UDP 1812 (authentication) and 1813 (accounting).

## Editions

RadiusOne runs as **RadiusOne Community** without a licence: clients and service plans, PPPoE and DHCP
access, NAS devices, FreeRADIUS, the network operations queue, reports, the monitoring overview and the
RADIUS server page, and local backups, for up to **150 clients**. **RadiusOne Pro** turns on with a licence key under
**Settings → License**: billing and invoices, customer notifications and admin alerts, NAS monitoring over
SNMP with traffic graphs, encrypted remote backups, and your own logo, by licence: up to **999 clients**
(Licence 1), up to **4,999** (Licence 2) or **no limit** (Licence 3). Archived clients do not count; over the limit, every client keeps
working and only new ones are refused. Keys are bought at
[account.pingomni.com](https://account.pingomni.com) and checked on the server (`app/Support/License.php`,
Ed25519); nothing is sent anywhere. An expired licence never stops RADIUS: Pro features keep running as
they were, and can be changed again once it is renewed. Compare the editions at
[pingomni.com/radiusone](https://pingomni.com/radiusone/#editions).

## Development

```bash
composer install && npm ci
npm run build          # or: npm run dev
bash tests/run.sh      # feature tests on a private, throw-away MariaDB
composer ci:check      # ESLint, TypeScript, Pint, PHPStan and the tests
```
