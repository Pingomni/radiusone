# Changelog

RadiusOne follows [semantic versioning](https://semver.org): **major** for changes that need action when
upgrading, **minor** for new features, **patch** for fixes. The version is in the `VERSION` file and shows in
the portal's sidebar and under Settings → System information.

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
