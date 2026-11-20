# Changelog

RadiusOne follows [semantic versioning](https://semver.org): **major** for changes that need action when
upgrading, **minor** for new features, **patch** for fixes. The version is in the `VERSION` file and shows in
the portal's sidebar and under Settings → System information.

## 2.39.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Menu:** NAS devices and OLTs are now tabs of Devices (All, Routers, OLTs, Needs a look).

## 2.38.1 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** Devices showed a router that never answered SNMP as "Working".

## 2.38.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Devices:** routers and OLTs in one list with their state. **OLT changelog:** every OLT's changes in one list.

## 2.37.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Sessions:** every open session on every router, with Disconnect. **PON ports:** the ports of every OLT, with a "Needs a look" tab.

## 2.36.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **ONUs:** one list of every ONU on every OLT (tabs, search, OLT filter, Waiting); Previous / Next on the ONU page; ONUs, OLTs and routers in the top search.

## 2.35.4 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`. (2.35.3 was not released: its build failed.)

- **Overview layout:** six figures split evenly at every width; panels in two columns with no empty holes.

## 2.35.2 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** an ONU that is weak only at the OLT end showed as green; the OLT reading now has its own colour and mark.

## 2.35.1 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** filtered ONU links open at the ONU list, not the page top; **Weak signal** is a tab of the list.

## 2.35.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Overview:** one strip of six clickable figures with icons (clients and ONUs: waiting, online, offline by cause, weak), an OLTs table and the ONU graph.

## 2.34.2 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** the installed phone app showed the old "R" icon.

## 2.34.1 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** the receipt's Save link was cut off on the client's Billing tab.

## 2.34.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Print and Save** for payment receipts on the invoice, online payment, Billing and client pages.

## 2.33.1 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **One design on every page:** dot + word instead of pills (OLT, ONU and Settings too), underlined sub-tabs, no icons in panel titles, OLT ports table fits 1440 px.

## 2.33.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Max ONUs per PON port** per OLT; adding an ONU on a full port is refused. Free places on the Ports tab.
- Migration `042_olt_max_onus.sql`.

## 2.32.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Rogue ONU detection:** on the Overview, in the OLT changelog, and as an admin alert (tick it in Settings → Notifications → Admin alerts).

## 2.31.2 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** SNMP "v2c" showed as "V2c".

## 2.31.1 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** cut-off tables and tabs on 1440 px screens (NAS list, client tabs and invoices, Settings tabs).
- **One status style** (dot + word) on every page; the client account panel shows only rows with a value.
- Smaller fixes: table edge shade, "Export CSV" everywhere, invoice buttons beside the title, fewer hint texts.

## 2.31.0 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **One count of weak ONUs everywhere** (online ONUs, weak at either end).
- **The company logo keeps its shape** on the dark menu and sign-in screen.

## 2.30.1 (2026-11-20)

Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Fixed:** cut-off signal labels on the ONU page.

## 2.30.0 (2026-11-19)

The ONU page in the new look, same layout. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Name as title, state as a dot and a word, the readings in one strip,** shorter notices. The ONU drawing stays.

## 2.29.0 (2026-11-19)

The last pages in the new look. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Sign-in screens on the dark frame, buttons beside titles, shorter help texts** on the remaining pages.

## 2.28.0 (2026-11-19)

The rest of the portal in the new look. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Buttons beside page titles, sentence-case titles, shorter notices** on the remaining pages.

## 2.27.0 (2026-11-19)

Billing, contracts and online payments in the new look. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Actions beside the title, status tabs with counts, statuses as a dot and a word.**

## 2.26.0 (2026-11-19)

The OLT page in the new look, same layout. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Figures in one strip, the state as a dot and a word, PON port boxes** with a state bar.
- **Fixed:** a red key figure was drawn as a red block.

## 2.25.0 (2026-11-19)

A new client page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Name as title, key figures in one strip,** and an Overview tab with data used and recent sessions.
- **Shorter hints and notices.**

## 2.24.0 (2026-11-19)

A new client list. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Views with counts,** including Expiring this week.
- **A side panel** with a client's subscription, balance, session and fibre signal.

## 2.23.0 (2026-11-19)

A new Overview page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **The title is the state of the network** in one sentence.
- **Needs attention, routers and expiring this week,** with the key figures in one strip.

## 2.22.0 (2026-11-19)

Shared parts of the new look. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Key figures in one strip of numbers** on every page that shows them.
- **The fibre signal scale** on the OLT's ONU list, the ONU page and the client's ONU tab.

## 2.21.0 (2026-11-19)

The portal's new frame. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **A light sheet in a dark frame,** and the menu in four groups with counts.
- **Search from the top bar (Ctrl K):** clients and invoices as you type.
- **Breadcrumb, page titles,** and your account at the bottom of the menu.

## 2.20.0 (2026-11-19)

The portal's new look, first part. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **New colours and typefaces** (Instrument Sans, Red Hat Mono), in light and dark.
- **Flatter controls:** buttons, fields, tags, tables and tabs.

## 2.19.1 (2026-11-19)

The OLT's page looks as it did before. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **The OLT's page is back to the 2.18.0 layout.**

## 2.19.0 (2026-11-19)

A new top for the OLT's page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **The OLT's front panel:** every slot, card and port with its light; a PON port shows its customers online, and choosing
  it lists its ONUs in place.
- **A customers column** (online, offline, weak signal, uplinks, needs a look), grouped tabs, and a layout for phones.

## 2.18.0 (2026-11-19)

A changelog for each OLT. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Changelog tab:** every change on the OLT, who made it and the commands sent, with search, filters and CSV export.
- **Found on the OLT:** ONUs added, removed, moved, renamed or turned off there, and the configuration's changed lines
  (secrets masked), even when done outside the portal.
- **ONU History tab** and the date an ONU was added.
- **On the server:** one new table, filled from the audit log by the update.

## 2.17.0 (2026-11-19)

VLANs and profiles on their own pages. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **VLANs:** names, descriptions, types and uplink ports at a glance; **Add VLAN** creates one or a range with its name,
  description and uplink ports in one go, undone if a step fails; **Edit** sends only what changed.
- **Profiles:** line and service profiles, traffic tables and DBA profiles on their own tab.

## 2.16.4 (2026-11-19)

A fix for the ONU's page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Choosing a section brings it to the top of the window,** so the whole section shows instead of its first lines.

## 2.16.3 (2026-11-19)

The ONU's page stays where you are. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **An action no longer rebuilds the page** or jumps to the top, and the tabs open at once: one read serves the whole
  page, the last read shows straight away, and Configure's choices are fetched ahead.
- **Fixed:** Traffic at the top of the ONU's page showed a dash in 2.16.2.

## 2.16.2 (2026-11-19)

The ONU's page opens faster. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **About 7 seconds instead of 14** to read what an ONU has now, and the last read shows at once when it is under
  5 minutes old. Every telnet read of an OLT is faster.

## 2.16.1 (2026-11-19)

A fix for the ONU's page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **The ONU's picture and status only changed after Get status or Live:** the page now reads again every 30 seconds by
  itself (10 with Live on), and Live no longer stops after the page was in the background.

## 2.16.0 (2026-11-19)

A live console on the OLT, a new OLT page, and a faster ONU page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Terminal:** a live console on the OLT, as with PuTTY: `?`, Tab, ↑, More pages and Ctrl+C work as on the OLT. For
  operators who may delete, on OLTs that allow changes; every line is audited. A new service, radiusone-olt-console,
  holds the sessions; without it the tab sends one line at a time.
- **OLT page:** health at a glance, the chassis with a light per port, PON ports in use, and a clearer ONU list.
- **ONU page:** one OLT login per page instead of up to three, and a read serves reloads and tabs for 30 seconds.
- **On the server:** a new service (radiusone-olt-console); no database change. No OLT is changed by the update.

## 2.15.0 (2026-11-18)

Routing or bridging, Ethernet port modes, and speeds by size. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **ONU mode:** switch between routing and bridging; the forms start from the ONU's running configuration.
- **Ethernet ports:** LAN, Access, Trunk (hybrid with an untagged VLAN) or Transparent, per port.
- **Speeds:** shown as 100M, 1G or Unlimited, and **Add a speed** creates a traffic table on the OLT.
- **On the server:** no database change. No OLT is changed by the update.

## 2.14.0 (2026-11-18)

A new ONU page, renaming in place, and the ONU's internet connection pushed over OMCI. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **ONU page:** device view with signal meters, a 24-hour status strip, and Location, Customer, Device and Service cards.
- **Rename:** the pencil next to the ONU's name changes its description on the OLT.
- **Configure over OMCI:** DHCP, PPPoE (also from the linked client's RADIUS login) or a fixed address on a VLAN, WAN and
  route policy profiles, LAN routing, and each LAN port's native VLAN.
- **On the server:** no database change. No OLT is changed by the update.

## 2.13.0 (2026-11-18)

A full page for each ONU, and more OLT settings. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **ONU page:** traffic and signal history, disconnection count, live status, running config, speed profiles per service
  port, LAN ports on and off, and restore defaults.
- **OLT:** signal levels per OLT, NTP servers, VLANs, and the VLANs on each uplink port.
- **On the server:** one new table and five new columns. No OLT is changed by the update.

## 2.12.0 (2026-11-18)

PON port and card actions, port graphs and a read-only command line. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **PON ports:** autofind on or off, restart all ONUs, turn the port off or on. **Cards:** restart a card.
- **A traffic graph for every OLT port**, and a **command line** that runs `display` commands only.
- **On the server:** one new table. No OLT is changed by the update.

## 2.11.0 (2026-11-18)

More of the OLT on its page, all read-only. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **OLT details:** model, software, uptime, power use, fan speeds, time servers, and whether it saves its configuration.
- **Ports:** PON autofind, average signal, transmit power and range; uplink type and VLANs. **Cards:** power use, fans in RPM.
- **VLANs and profiles**, **Config backups** (a sealed copy each day it changed, up to 30), **History**, and **Export MAC table**.
- **On the server:** one new table and nine new columns. No OLT is changed by the update.

## 2.10.0 (2026-11-18)

ONU management on the OLT. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Changes are off per OLT** until **Allow changes from the portal** is turned on under Edit.
- **New ONUs:** lists ONUs plugged in but not added, and adds one in router or bridge mode, linked to a client if you want.
- **An ONU's page:** live state, signal, internet connection and LAN ports; restart, turn off and on, rename, remove.
- **Safe by design:** the serial number is checked on the OLT before every change, a failed add is undone, and every
  change is in the audit log with the commands sent.
- **Fixed:** an OLT that prints a notice just after login no longer breaks the MAC table read.
- **On the server:** three new columns. No OLT is changed by the update.

## 2.9.2 (2026-11-06)

A fix for 2.9.0. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **The online payments check failed** ("Unknown lifecycle job"): it now runs every 5 minutes and shows under
  Monitoring → Background jobs.

## 2.9.1 (2026-11-06)

A fix for 2.9.0. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **"Application error" after updating to 2.9.0:** the portal's database account could not read the new contracts and
  online payments tables. The update grants them; a test now checks every table is granted.

## 2.9.0 (2026-11-06)

Contracts and a pay page with POK and Stripe. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Contracts:** issued from your template, kept as issued, printed as a PDF; nightly invoices follow them.
- **Pay page** on its own address: username plus the last 4 phone digits, 1 to 12 months inside the contract, paid on
  POK's or Stripe's page. A payment counts only once the service confirms it; then the invoice is paid and the service renewed.
- **Test and live credentials** per service, starting in test mode; test payments never pay an invoice.
- **Online payments** page, a per-client switch, and a 15-minute wait after 5 wrong phone numbers.
- **On the server:** two new tables and one new column. Customers stay connected.

## 2.8.1 (2026-11-06)

The user guide moves to pingomni.com. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Documentation** opens [pingomni.com/docs/radiusone](https://pingomni.com/docs/radiusone/) in a new tab, for every
  operator; the Backblaze B2, Cloudflare R2 and Telegram setup links open the matching part. The portal no longer carries
  its own copy, and `/docs` sends old bookmarks there.
- **On the server:** nothing changes. Customers stay connected.

## 2.8.0 (2026-11-03)

An OLT's own hardware. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Ports tab:** each uplink and PON port's state, speed, traffic and errors, and the ONUs online on each PON port.
- **Cards tab:** each card's status, role, CPU, memory, temperature and power input, and fans if the OLT reports them.
- **Graphs:** control card CPU and memory, the hottest card, and uplink traffic, alongside the ONUs.
- **Monitoring:** the OLT's card shows CPU, memory, temperature, cards and ports. **Needs attention** lists a faulty,
  too hot or too busy card, a power or fan fault, and an uplink that's down.
- **On the server:** four new database tables and two new columns. Customers stay connected.

## 2.7.0 (2026-11-03)

OLTs on the Monitoring page. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **A card for each OLT on Monitoring:** ONUs online, offline and with a weak signal, and how old the list is.
- **Needs attention:** a failed read, an OLT no longer being read, and a PON port whose ONUs all went offline recently.
- **A graph on the OLT's page:** ONUs online, offline and with a weak signal, over up to 7 days.
- **Poll all devices now** also reads the OLTs, within a minute.
- **On the server:** one new database table and one new column. Customers stay connected.

## 2.6.0 (2026-11-03)

See your OLT and which client is on which ONU. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **OLT in the menu:** add a Huawei MA5800-X7 with SNMP, and optionally telnet. It shows each ONU's port, status, signal,
  distance and last disconnect. Read-only: the portal can't change anything on the OLT.
- **Client mapping:** clients are linked to their ONU from the session's MAC, or an ONU description matching the username,
  or by hand. A new **ONU** tab on each client shows it.
- **On the server:** four new database tables, and a job that does nothing until an OLT is added.

## 2.5.1 (2026-11-03)

Get back in when you're locked out. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Reset a portal password from the terminal:** `sudo radiusone-root-artisan radiusone:reset-password <login>`. It asks
  for the new password without showing it, logs out open sessions and records the reset in the audit log. Add
  `--reset-2fa` if the authenticator phone is lost too.

## 2.5.0 (2026-11-02)

See why a router can't connect. Update with **Settings → Overview → Software update** or `sudo radiusone-update`.

- **Diagnostics tab on each NAS:** a connection checklist, a 60-second packet test that also checks the shared secret
  on signed requests, and recent errors and rejected logins in plain words.
- **Unknown routers on the NAS list:** addresses sending RADIUS requests that aren't in your list, with **Add as NAS**.
- **On the server:** FreeRADIUS login logging is switched on, without passwords, from its next restart. `tcpdump` is
  installed if missing. If the firewall is on, its rules reload once with a new line logging dropped RADIUS requests.

## 2.4.0 (2026-11-02)

A firewall in the portal. Update with **Settings → Overview → Software update** or `sudo radiusone-update`. The
firewall stays off until you turn it on.

- **Firewall under Settings → Firewall.** Turn this server's firewall on and off, and manage its rules from the
  portal. It starts off, with a template you can change: the portal from anywhere, RADIUS from your routers, and
  SSH only from your management networks. If the portal can't reach the server within a minute of a change, the
  previous rules come back. See INSTALL.md. Runs `radiusone-postinstall` on update, which changes no rules.
- **Fresh installs work again.** The installer gave the migration user its database rights too late.
- **The repository moved to `Pingomni/radiusone`.** Update checks and links use the new address; the old one redirects.

## 2.3.4 (2026-10-30)

The updater keeps the web user's access to `.env`. Update with `sudo radiusone-update` as usual.

- **The portal reads its settings after an update.** Copying a release made the whole app folder its owner's,
  `.env` included, so the web user lost access to `.env` (installed as root:www-data, 0640). A cached copy of the
  settings hid it until 2.3.3 rightly cleared that cache, and the update to 2.3.3 stopped at the server set-up and
  rolled back. `.env` now keeps its owner and mode.

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
