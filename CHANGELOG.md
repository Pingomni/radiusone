# Changelog

RadiusOne follows [semantic versioning](https://semver.org): **major** for changes that need action when
upgrading, **minor** for new features, **patch** for fixes. The version is in the `VERSION` file and shows in
the portal's sidebar and under Settings → System information.

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
