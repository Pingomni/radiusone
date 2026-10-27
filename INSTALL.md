# Installing RadiusOne

One script, `install.sh`, turns a fresh Ubuntu 24.04 server into a running RadiusOne system: web portal,
database and FreeRADIUS, connected and secured. Nothing needs to be installed beforehand.

- [Requirements](#requirements)
- [Install](#install)
- [Options](#options)
- [What the installer does](#what-the-installer-does)
- [After installing](#after-installing)
- [Connecting a router](#connecting-a-router)
- [Where things are](#where-things-are)
- [Ports](#ports)
- [Running it again and updating](#running-it-again-and-updating)
- [Troubleshooting](#troubleshooting)

## Requirements

| | |
| --- | --- |
| Operating system | **Ubuntu 24.04 LTS** (server or minimal). The script stops on any other version. |
| Access | `root`, or a user who can run `sudo` |
| Internet | Needed during the install (Ubuntu packages; from the source also Node.js and the PHP and JavaScript libraries) |
| Server | A fresh server is best. 2 CPU cores, 4 GB RAM and 20 GB disk are enough to start; give the database more memory for thousands of clients. |
| Domain (optional) | A DNS name pointing at the server, if you want a trusted Let's Encrypt certificate |

The script refuses to run on a server that already has a `radius` database or an older RadiusOne install
that it did not create, so it cannot overwrite an existing system by accident (see `--force`).

## Install

From a release (`radiusone-X.Y.Z.tar.gz` on the GitHub releases page: the libraries and web pages are
included, so the server needs no Node.js or Composer):

```bash
sudo mkdir /opt/radiusone
curl -fsSL https://github.com/n-geraldo/radiusone/releases/download/vX.Y.Z/radiusone-X.Y.Z.tar.gz \
  | sudo tar -xz -C /opt/radiusone --strip-components=1
cd /opt/radiusone
sudo bash install.sh
```

Or from the source, which the installer builds:

```bash
sudo apt-get install -y git          # if git is not there yet
git clone <repository URL> /opt/radiusone
cd /opt/radiusone
sudo bash install.sh
```

Use `/opt/radiusone`: the updater (`radiusone-update`) installs new releases there. The install takes a few
minutes. Each stage is printed as it runs (`==> Installing packages ...`). If a stage fails, the script
stops there and says which one.

At the end you see a summary like this:

```
==> RadiusOne v2.0.0 is installed.

    nginx          running
    php8.3-fpm     running
    mariadb        running
    freeradius     running
    cron           running
    login page     HTTP 200

    Portal:    https://203.0.113.10
    Username:  admin
    Password:  6f1c0e9a2b7d4c8e5a3f1b90

    The password is shown only now: save it. ...
```

**Save the password straight away.** It is shown only once and is not stored anywhere in readable form.

## Options

| Option | Default | What it does |
| --- | --- | --- |
| `--domain NAME` | the server's first IP address | The name or IP the portal is reached on. Used for the web address and the certificate. |
| `--email ADDRESS` | none | With a real `--domain`: requests a free Let's Encrypt certificate for it. The domain must already point at this server, and port 80 must be reachable from the internet. Without this option a self-signed certificate is used. |
| `--admin-user NAME` | `admin` | Username of the first administrator (3–80 letters, digits, `.`, `-`, `_`) |
| `--admin-email ADDR` | `admin@<domain>` | Email of the first administrator |
| `--timezone ZONE` | unchanged | Sets the server time zone before anything is installed, e.g. `Europe/Tirane`. Billing dates and expiry times follow it. Choose it now: changing it later (Settings → Regional) has to convert stored times. |
| `--force` | off | Runs even though the server already has a `radius` database or an older install. Existing data is kept, but the web, FreeRADIUS and cron configuration files are replaced. |
| `--help` | | Shows the options |

Examples:

```bash
# Quick start on an IP address, self-signed certificate
sudo bash install.sh

# Production with a domain, trusted certificate and local time zone
sudo bash install.sh --domain radius.example.com --email it@example.com --timezone Europe/Tirane

# Your own administrator name
sudo bash install.sh --domain radius.example.com --admin-user jane --admin-email jane@example.com
```

## What the installer does

1. **Checks the server.** Ubuntu 24.04, root, the project folder and the options.
2. **Installs packages.** Everything that is missing comes from Ubuntu's repositories; anything already
   installed is left as it is.
   - nginx (web server)
   - MariaDB server and client
   - FreeRADIUS, its MySQL module and `radclient` (used to disconnect sessions)
   - PHP 8.3: FPM, command line, and the mysql, mbstring, xml, curl, zip, intl and bcmath extensions
   - Composer, curl, git, unzip, openssl, sudo, cron, logrotate, ping
   - certbot, only with `--email`
   - **Node.js 22** from nodejs.org, with its checksum verified. It is only used to build the web pages
     (Ubuntu's own Node.js is too old), and only if a recent enough version is not already installed.
3. **Generates keys and passwords.** Random values for the encryption key that protects saved router,
   SNMP and gateway credentials, and for four database users, one per role:

   | Database user | Used by | Can do |
   | --- | --- | --- |
   | `radiusone_portal` | the web portal and its scheduled jobs | read and write the tables it manages, nothing else |
   | `radiusone_radius` | FreeRADIUS | read users, groups and the NAS list; write accounting and login records |
   | `radiusone_migration` | database upgrades (`radiusone:migrate`) | change the schema |
   | `radiusone_backup` | the nightly backup | read only |

   They are saved in `/etc/radiusone/` and readable only by root (the portal's file also by the web
   server). None of them is shown, because you never need to type them.
4. **Creates the database.** A `radius` database (UTF-8), the four users, FreeRADIUS's standard tables, then
   the application's tables through its own migrations. The exact permissions are in
   `ops/install/grants.sql`.
5. **Sets up the application.** Writes `.env` (production mode, HTTPS only, secure cookies), installs the
   PHP libraries, generates the application key and builds the web pages.
6. **Connects FreeRADIUS to the database.**
   - Users, groups and accounting come from the database.
   - The NAS list comes from the portal. Only NAS devices that are switched on, and not being removed, are
     accepted as RADIUS clients.
   - Passwords are never written to the login log.
   - Simultaneous-Use (the per-customer session limit) is enforced.

   The original files are kept next to the changed ones as `*.radiusone-orig`. The configuration is checked
   (`freeradius -C`) before FreeRADIUS is restarted.
7. **Configures HTTPS and the web server.** HTTP redirects to HTTPS. A self-signed certificate is created
   (valid 10 years), or a Let's Encrypt one with `--email`, which renews by itself. Uploads are allowed up to
   24 MB per request, for ID documents on the client form.
8. **Schedules jobs and backups.**
   - The app's scheduler runs every minute: billing at 03:15, SNMP polling, admin alerts, expiry,
     notifications, network changes and clean-up.
   - The database and documents are backed up nightly at 02:15.
   - Log rotation is set up.
   - Small root helpers and a narrow `sudo` rule are installed so the portal's **Restart** buttons, the
     **time zone** switch and **Back up now** work, and nothing else.
   - If the `ufw` firewall is active, ports 80, 443, 1812/udp and 1813/udp are opened.
9. **Creates the first administrator**, a superadmin with a random 24-character password, and prints it.
   If the portal already has users, none is created.

## After installing

1. **Open the portal** at the address shown, e.g. `https://radius.example.com`.
   With a self-signed certificate the browser warns once; choose *Advanced → Continue*.
2. **Sign in** with the username and password from the summary.
3. **Set up two-factor authentication.** Scan the QR code with an authenticator app (Google Authenticator,
   Microsoft Authenticator, 1Password...) and enter the 6-digit code. This is required for every operator.
4. **Change the password** if you like: *Profile → Password*.
5. **Set the basics** under *Settings*: company name and logo (Branding), currency and time zone (Regional),
   email or SMS gateways (Notifications), and remote backup copies (Backups).
6. **Create service plans** under *Services* (speeds, price, billing period).
7. **Add your routers** under *NAS* (see below), then your **clients**.

## Connecting a router

1. In the portal: *NAS → New NAS device*. Enter the router's IP address (the address it sends RADIUS from),
   a name, the vendor and a shared secret.
2. **Restart FreeRADIUS** so it loads the new NAS: *Monitoring → RADIUS server → Restart*. Until then the
   NAS list shows *Waiting for update*.
3. On the router, follow the steps on the NAS page's *Router setup* tab. For MikroTik RouterOS:
   - `/radius add address=<this server> secret=<shared secret> service=ppp src-address=<NAS IP>`
   - `/ppp aaa set use-radius=yes interim-update=1m`
   - `/radius incoming set accept=yes port=3799` (lets the portal disconnect sessions)
   - Optional: SNMP for monitoring (*NAS → SNMP* tab), and the RouterOS API (*NAS → API* tab)

MikroTik RouterOS PPPoE is tested end to end. Cisco IOS XE is marked *Beta*: it follows Cisco's documentation
but has not been tested on a real router yet. DHCP (MAC) login is not finished yet; see the project notes.

## Where things are

| What | Where |
| --- | --- |
| Project (code, `.env`) | `/opt/radiusone` |
| Keys and database passwords | `/etc/radiusone/portal.env`, `radius.env`, `migration.env`, `backup.env` |
| Customer documents, logos | `/var/lib/radiusone/` |
| Backups | `/var/backups/radiusone/` (status in `status.json`; remote copies under *Settings → Backups*) |
| Job logs | `/var/log/radiusone/` (scheduler, billing, SNMP, alerts, `backup.log`...) |
| Updates | `/usr/local/sbin/radiusone-update`; progress and log in `/var/lib/radiusone/update-status/` |
| App log | `storage/logs/` in the project folder |
| Scheduled jobs | `/etc/cron.d/radiusone` |
| Web server | `/etc/nginx/sites-available/radiusone` |
| Certificate | `/etc/ssl/radiusone/` (self-signed) or `/etc/letsencrypt/live/<domain>/` |
| FreeRADIUS | `/etc/freeradius/3.0/mods-available/sql`, `mods-config/sql/main/mysql/queries.conf`, `sites-available/default` |
| Portal helpers | `/usr/local/sbin/radiusone-*`, `/etc/sudoers.d/radiusone`, `radiusone-update.path` |

## Ports

| Port | Protocol | From | For |
| --- | --- | --- | --- |
| 80 | TCP | browsers, Let's Encrypt | redirect to HTTPS, certificate checks |
| 443 | TCP | browsers | the portal |
| 1812 | UDP | your routers | RADIUS authentication |
| 1813 | UDP | your routers | RADIUS accounting |

Outgoing, the server reaches your routers on UDP 3799 (disconnect/CoA), UDP 161 (SNMP, if set up) and
TCP 8729 (RouterOS API, if switched on). Restrict 1812/1813 to your routers' addresses in your firewall.
MariaDB (3306) listens only on the server itself.

## Running it again and updating

Running `install.sh` again is safe. It keeps the keys, database passwords, data and administrators, and
re-applies the configuration. Use it to repair a broken setup, or to switch to Let's Encrypt later:

```bash
sudo bash install.sh --domain radius.example.com --email it@example.com
```

### Updating from the portal

*Settings → Overview → Software update* shows the installed version. **Check for updates** reads the latest
release on GitHub and shows what changed; **Install RadiusOne x.y.z** installs it. The portal only leaves a
request; `radiusone-update` installs it as root (started by `radiusone-update.path`, as DNSOne does):

1. The release (`radiusone-x.y.z.tar.gz`) is downloaded and its checksum checked.
2. The database is backed up and the app folder archived.
3. The portal pauses (maintenance mode) for a few minutes. RADIUS keeps authenticating customers.
4. The new files are copied in (`.env`, `storage` and uploads stay), database migrations and permissions are
   applied, the helpers are refreshed and the portal comes back.

The page shows the progress and reloads when it is done. If a step fails, the previous version comes back
and the page says where it stopped; the full log is in `/var/lib/radiusone/update-status/update.log`.

Only **releases** are installed: version tags such as `v2.3.0` with their download (see
[CHANGELOG.md](CHANGELOG.md)). The server needs no git, Node.js or Composer, and no access to the repository
beyond the public releases.

### Updating by hand

```bash
sudo radiusone-update --check     # what is installed and what is available
sudo radiusone-update             # install the latest release
sudo radiusone-update v2.3.0      # install that release
```

### Servers installed before 2.3

Before 2.3 RadiusOne used the names of the project it grew from: the app in `/opt/freeradius-web-laravel`,
the folders `/etc/freeradius-web`, `/var/lib/freeradius-web`..., the database accounts `frweb_*` and the
`frweb-*` helpers. 2.3 moves such a server to the RadiusOne names in two steps:

1. **Install 2.3** with the update button as usual. It still installs in the old folder.
2. **Finish moving to RadiusOne** appears under *Software update*: it moves the app to `/opt/radiusone` and
   the folders to their `radiusone` names, renames the database accounts (`RENAME USER` keeps their passwords
   and rights), and rewrites `.env`, cron, logrotate, nginx, sudoers and FreeRADIUS's SQL login. The old paths
   stay as links, so scripts that name them keep working. Operators sign in once more afterwards.

Or both steps in one go, on the server:

```bash
curl -fsSL https://raw.githubusercontent.com/n-geraldo/radiusone/v2.3.0/ops/radiusone-update | sudo bash -s v2.3.0
```

To see first what the move changes, without changing anything (from the 2.3 app folder):
`sudo bash ops/radiusone-upgrade-2.3 --dry-run`. It backs up the database and archives every file it changes
in `/var/backups/radiusone-move-<date>/`; if a step fails it puts everything back.

## Troubleshooting

| Problem | What to check |
| --- | --- |
| The script stops with *Install failed: line N* | The line above says which stage failed. Fix the cause (often no internet, or a package mirror) and run `sudo bash install.sh` again. |
| *this server already has a 'radius' database* | The server was used before. Use a fresh server, or `--force` if you are sure the database can stay as it is. |
| *Let's Encrypt failed* | The domain must point at this server and port 80 must be open from the internet. The self-signed certificate stays in place; run the installer again once DNS is right. |
| The login page does not open | `systemctl status nginx php8.3-fpm`, then `tail /var/log/nginx/error.log` and `storage/logs/` |
| Routers are rejected | `systemctl status freeradius`; is the NAS saved in the portal and FreeRADIUS restarted since? Check the shared secret, and watch live with `sudo systemctl stop freeradius && sudo freeradius -X` (start the service again afterwards). |
| Lost the administrator password | Another superadmin can reset it under *Settings → Users*. |
| *Check for updates* says GitHub could not be reached | The server needs HTTPS access to `api.github.com` and `github.com`. Test with `sudo radiusone-update --check`. |
| Scheduled jobs do not run | `cat /etc/cron.d/radiusone` and `tail /var/log/radiusone/scheduler.log`; *Monitoring* shows when each job last ran. |
