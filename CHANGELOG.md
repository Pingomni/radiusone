# Changelog

RadiusOne follows [semantic versioning](https://semver.org): **major** for changes that need action when
upgrading, **minor** for new features, **patch** for fixes. The version is in the `VERSION` file and shows in
the portal's sidebar and under Settings → System information.

## 2.3.3 (2026-10-30)

Fixes the updater. Update with `sudo radiusone-update` as usual. (The 2.3.2 tag was never published: its
release notes were missing, so it ships as 2.3.3.)

- **An update no longer stops on a package the new release leaves out.** The updater kept artisan's list of
  installed packages from before the new files were copied; when a package was gone (Laravel Pail, on a server
  installed from the source), the update stopped before the database step and rolled back. The lists are now made
  again after the copy.
- **The portal works after a rollback.** The rollback archive leaves out the session, cache and compiled-view
  folders, and putting the old version back did not create them again, so every page answered with an error until
  they were made by hand. The rollback now creates them.
- **RadiusOne's new mark**, the Key, from the PingOmni family: favicon, home-screen icon, sign-in page and invoices.

## 2.3.1 (2026-10-28)

Security fixes from a full audit. Install it on every server.

- **Root never acts in www-data's folders.** The data, backup and root-log folders are now root's
  (`/var/lib/radiusone`, `/var/backups/radiusone`, `/var/log/radiusone-root`); www-data keeps only its own
  subfolders. The nightly backup, *Back up now*, the time zone change, the updater and the 2.3 move no longer
  write, copy or extract through names the web user could change, and copies of www-data's files are made as
  www-data.
- **`php artisan` as root goes through `radiusone-root-artisan`.** The in-app docs show it for every root command,
  and `artisan` refuses to start as root otherwise (it would load cache files the web user can write).
- **Restore runs the database client in sandbox mode** and, as root, accepts only a backup file root owns in a folder
  only root can write.
- **Backup destinations are checked again when the backup runs**, not only when they are saved.
- **Sign-in throttling counts per account**, whatever spelling of the user name is typed.
- **Licence keys must be for RadiusOne** (the product named in the key, or a client limit for older keys).
- **Suspensions, expiry and data caps reach online customers.** A session whose router reports rarely, or not at
  all, is shown under *Operations* for a check instead of being taken as offline.
- **Usernames compare the same everywhere** (migration 030; circuit and DHCP identifiers stay exact).

## 2.3.0 (2026-10-27)

- **Updates work like DNSOne's.** Every release is published on GitHub as `radiusone-X.Y.Z.tar.gz` with the
  PHP libraries and the web pages built in, plus its checksum. *Settings → Overview → Software update* reads
  the latest release, and **Install** only leaves a request: `radiusone-update` (started by
  `radiusone-update.path`) downloads and checks the release, backs up the database, archives the app, installs
  it, applies migrations and permissions, and rolls back if a step fails. The server needs no git, Composer or
  Node.js, and no access to the repository. On the server: `sudo radiusone-update` (`--check`, or a version).
- **RadiusOne names everywhere.** The names of the project RadiusOne grew from are gone:
  - app `/opt/radiusone`; folders `/etc/radiusone`, `/var/lib/radiusone`, `/var/log/radiusone`,
    `/var/backups/radiusone`;
  - database accounts `radiusone_portal`, `radiusone_radius`, `radiusone_migration`, `radiusone_backup`;
  - commands `php artisan radiusone:...` (the `frweb:...` names still work);
  - settings `RADIUSONE_...` in `.env` (the `FRWEB_...` names are still read);
  - helpers `/usr/local/sbin/radiusone-*`, `/etc/sudoers.d/radiusone`;
  - backups `radiusone-*.sql.gz` and, remotely, `radiusone-db-...`/`radiusone-docs-...` (older copies with the
    old names are still listed, restored and pruned).
- **Moving a server installed before 2.3.** Install 2.3 with the update button as usual, then press **Finish
  moving to RadiusOne** under *Software update* (or run `sudo radiusone-update --move`). It backs up the
  database, archives every file it changes, moves the app and the folders (the old paths stay as links),
  renames the database accounts with their passwords and rights, and rewrites `.env`, cron, logrotate, nginx,
  sudoers and FreeRADIUS's SQL login. If a step fails, everything goes back. Operators sign in once more
  afterwards. To see what it would change first: `sudo bash ops/radiusone-upgrade-2.3 --dry-run`.
- **Fixed:** the time zone and Back up now buttons could fail with a PHP notice on some servers.

## 2.2.4 (2026-10-27)

- **Fixed: "405 Method Not Allowed" after an update.** Reloading or reopening the page a phone was left on after
  pressing *Install* now opens Settings. Any other address that only a button uses shows the portal's own error
  page, with a way back.

## 2.2.3 (2026-10-27)

- **Software update** (Settings → Overview) is laid out like DNSOne's: what is installed, what is available,
  the release notes (or the update log) in one box, and **Install RadiusOne X**, **Check for updates** and
  **Release notes** buttons.

## 2.2.2 (2026-10-27)

- **Phone menu:** the drawer closes with a small ✕ beside the logo, instead of a wide "Close navigation" box
  above it.

## 2.2.1 (2026-10-27)

- **PingOmni teal.** The portal's colours follow the PingOmni brand: links, buttons, focus rings, the menu
  marker and charts are teal in the light and dark themes, and the logo, favicon and app icons have the teal
  gradient. Status colours, the NAS traffic chart and every page stay as they were.

## 2.2.0 (2026-10-27)

- **RadiusOne Community and RadiusOne Pro.** Without a licence, RadiusOne runs as Community: clients,
  services, NAS devices, RADIUS, the network queue, reports, monitoring overview, the RADIUS server page
  and local backups. A RadiusOne Pro key, added under **Settings → License**, turns on billing and invoices,
  customer notifications and admin alerts, NAS monitoring over SNMP with traffic graphs, remote backups
  and your logo. Keys come from your PingOmni account (account.pingomni.com) and are checked on the
  server.
- **The server registers with your PingOmni account**, as DNSOne does: with a key saved, it activates and
  checks in twice a day, so the licence's page in your account shows the server and its last check-in, and
  after a renewal the server installs the new key by itself. It sends the key, a hash of `/etc/machine-id`
  and the host name. Report only: Pro never depends on it; Settings → License shows the registration, and
  `RADIUSONE_ACCOUNT_URL=` (empty) in `.env` turns it off. `php artisan license:check-in --force` runs it now.
- **Clients per edition:** Community up to 150 clients; RadiusOne Pro by licence: up to 999 (Licence 1), up to
  4,999 (Licence 2) or no limit (Licence 3).
  Archived clients do not count. At the limit, every client keeps working and RADIUS is untouched; only
  adding a new client is refused, with what to do. Settings → License shows how many are in use.
- An expired licence never stops RADIUS or a Pro feature already set up: Pro pages stay readable and Pro
  jobs keep running, but Pro settings cannot be changed until the licence is renewed.
- **Upgrading:** add your licence key under Settings → License right after updating. Until then billing,
  notifications, NAS monitoring, remote backups and a custom logo are off (their data is kept), and a server
  with more than 150 clients cannot add new ones.

## 2.1.8 (2026-10-21)

- **Accurate daily usage:** a background job (every 5 minutes) records each session's traffic on the day it
  happened, so a customer online all week shows traffic on every day. The client Usage card reads it. On
  the first run, past sessions are filled in on their last day.
- **Data caps per plan:** set a cap in GB (download + upload) on a service plan, and choose what happens
  when a customer reaches it: notify only, or slow down to another plan until the next cycle. A customer's
  cycle ends on their expiry date. Customers get the new "Data cap 80% used" and "Data cap reached"
  messages (turn them on under Settings → Notifications → Templates). The plans list shows each cap, the
  Usage card shows the cycle's use and any slow-down, and the daily admin report lists customers who
  reached their cap.
- Database change: migration 029 (usage tables and two plan columns); the update applies it.

## 2.1.7 (2026-10-21)

- **Usage card on the client page:** download and upload per day for the last 7 or 30 days, or per month for
  12 months, with totals. Select a bar to see that day's figures. It sits at the top of the right column
  (first on phones). RADIUS counts traffic per session, so each session counts on the day it last reported.
- Light theme: placeholder text, empty table cells and small notes are easier to read.

## 2.1.6 (2026-10-21)

Dark theme, checked on every page:

- Placeholder text in fields and search boxes, the dash in empty table cells and small notes ("You",
  "In use by…") are brighter and now easy to read; secondary text moved one step brighter to stay above them.
- The count on the selected invoice tab (Billing) is readable: white on the solid button blue.

## 2.1.5 (2026-10-21)

Fields, buttons and text in a row now line up:

- Checkboxes sit centred on the line of fields and buttons beside them (they sat a few pixels high).
- On touch screens, filter bars (Clients, Reports, Logs), the password Show buttons and the top-bar buttons are
  the same height as the fields next to them.
- Service plan form: "Billing mode" has a short label with the explanation underneath, so its field stays
  on the same line as its neighbours.

## 2.1.4 (2026-10-21)

Tablets, phones held sideways and large screens:

- Wide tables that scroll sideways inside their panel (clients, invoices on tablets) show a soft shade at the
  cut edge, so it is clear more columns follow.
- The section name above the page title stays on one line on tablets.
- On a phone held sideways the top bar scrolls away with the page instead of covering a quarter of the screen.

## 2.1.3 (2026-10-21)

Layout fixes found by checking every page on a phone and on a computer:

- Phones: key-figure cards drop their icons so amounts fit on one line; panel titles sit above their buttons
  instead of squeezing beside them; status tabs (invoices, notifications) fill whole rows; tab bars
  (settings, client, documentation) scroll to the current tab.
- Status labels wrap inside narrow cards instead of running past the edge (Monitoring).
- The client's Account Overview header spans the full card, and the invoice sheet lines up its parties,
  lines and totals.

## 2.1.2 (2026-10-21)

Phones and tablets, for technicians working on site:

- The client search in the top bar (user, name, phone, IP or MAC) stays on phones, as a second row.
- Logout is an icon on phones, so page titles fit.
- On touch screens, buttons and fields are larger, and tapping a field no longer zooms the page.
- Client list filters sit two per row; connection diagnostics read as one titled card per check; action
  links in table cards line up; dashboard figures show their full labels.

## 2.1.1 (2026-10-21)

Security fixes. Updating from the portal (or with `install.sh`) applies them; nothing to do by hand.

- **Root jobs no longer run code from the web user's cache folder.** The nightly backup, Back up now, the
  time zone change, the installer and software updates ran Laravel as root, which loads cache files the
  web server's user (www-data) can write. A compromised portal could have used them to run code as root.
  These jobs now keep their own private cache (new `/usr/local/sbin/frweb-root-artisan`), and the update
  repoints existing root cron lines at it.
- **Turning off two-factor takes a code.** Profile → Disable two-factor asks for a current code from the
  authenticator app; a signed-in session alone is no longer enough. Roles that require two-factor
  (superadmin, admin, NOC, billing, security) cannot turn it off, as already enforced under Settings → Users.

## 2.1.0 (2026-10-20)

- **Software update from the portal:** Settings → Overview → Software update checks GitHub for a newer
  release, shows what changed and installs it: database backup first, a few minutes of maintenance mode
  (RADIUS keeps working), automatic roll-back to the previous version if a step fails. Only tagged releases
  are installed. A private repository needs a read-only deploy key on the server (see INSTALL.md).
- CI runs on Ubuntu 24.04, like production and the installer.

## 2.0.1 (2026-10-20)

- Emails, SMS texts and PDFs use the brand name: the company name under Settings → Branding, or RadiusOne
  when none is set. The email sender name always follows it (the separate From name field, which kept an
  old name once saved, is gone), and PDF file properties name it instead of "FreeRADIUS Web".

## 2.0.0 (2026-10-20)

Version 2 is a rewrite of the plain-PHP portal (version 1) on Laravel 13, Inertia and React, on the same
database. In production since 2026-10-15.

- **Clients and services:** PPPoE clients, service plans with speeds and prices, static IP pools, ID
  documents, suspension, expiry and archiving.
- **Billing:** invoices with status tabs and search, payments and receipts (PDF), recurring invoices every
  night. A customer whose invoice history needs review no longer stops the run for everyone else; the
  Billing page lists them.
- **NAS devices:** MikroTik RouterOS (tested end to end) and Cisco IOS XE (Beta). Disconnects over RADIUS
  CoA by default, or the RouterOS API when switched on. NAS addresses must be valid IPs.
- **Monitoring:** RADIUS server and service status with Restart buttons, NAS monitoring over SNMP
  (v1/v2c/v3) with history graphs and network tables (IP addresses, ARP, MAC, BGP, OSPF). The NAS list shows
  each device's SNMP state.
- **Notifications:** email and SMS to customers, admin alerts and reports by email or Telegram.
- **Settings:** users and roles with required two-factor authentication, branding and company name,
  regional settings and time zone, data retention, encrypted backups to Backblaze B2, Cloudflare R2 or SFTP.
- **Install:** `install.sh` sets up a complete system on a fresh Ubuntu 24.04 server (see INSTALL.md).

Not finished yet: DHCP (MAC) login needs a dedicated FreeRADIUS listener and is not usable in this release.
