import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { revealCurrent } from '@/components/tabs';
import AppLayout from '@/layouts/AppLayout';

/*
 * Administrator documentation: what every page does, how to set it up and how it works.
 * Examples use documentation placeholders only (192.0.2.x, example.com, 123456789), never this
 * install's hosts, addresses or credentials.
 */

type Section = { id: string; title: string; icon: string; body: ReactNode };

function Steps({ children }: { children: ReactNode }) {
    return <ol className="docs-steps">{children}</ol>;
}

function Note({ tone = 'info', children }: { tone?: 'info' | 'warning'; children: ReactNode }) {
    return <div className={`docs-note docs-note-${tone}`}><Icon name={tone === 'warning' ? 'shield' : 'message'} /><div>{children}</div></div>;
}

function Table({ head, rows }: { head: string[]; rows: ReactNode[][] }) {
    return (
        <div className="docs-table">
            <table>
                <thead><tr>{head.map((h) => <th key={h}>{h}</th>)}</tr></thead>
                <tbody>{rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j}>{c}</td>)}</tr>)}</tbody>
            </table>
        </div>
    );
}

const SECTIONS: Section[] = [
    {
        id: 'start',
        title: 'Getting started',
        icon: 'dashboard',
        body: (
            <>
                <p>RadiusOne is the operator portal for the FreeRADIUS server: customers and their internet plans, the routers (NAS) they connect through, billing, monitoring, and messages to customers and to your team. Everything a page changes is stored in the same database FreeRADIUS reads, so most changes apply to the next customer login.</p>
                <h3>Signing in</h3>
                <Steps>
                    <li>Open the portal and sign in with your operator username and password.</li>
                    <li>If two-factor authentication is on for your account, enter the 6-digit code from your authenticator app. If an administrator made it mandatory, you are asked to set it up on your first sign-in.</li>
                </Steps>
                <h3>Staying signed in</h3>
                <p>Once you are signed in (password, plus the 6-digit code when two-factor is on), the portal does not ask for your password again for sensitive actions such as saving a NAS or gateway, revealing a customer password or restarting services. Sign out when you leave a shared computer: anyone at an open session can do what your role allows.</p>
                <h3>Roles</h3>
                <Table
                    head={['Role', 'What it can do']}
                    rows={[
                        ['superadmin', 'Everything, including Settings, operators, backups, service restarts and this documentation.'],
                        ['admin', 'Clients, services, NAS, billing, reports and logs. No Settings.'],
                        ['noc', 'Provision services, manage NAS and review network operations. No payments.'],
                        ['billing', 'Invoices and payments. Cannot lift manual or security restrictions.'],
                        ['security', 'Apply and lift security restrictions, read logs. No plan or payment changes.'],
                        ['support', 'Read-only connection diagnostics.'],
                        ['view', 'Read-only access to clients, services, NAS, billing, reports and logs.'],
                    ]}
                />
                <p>Every change is written to the audit log (<Link href="/logs">Logs</Link>) with the operator who made it.</p>
            </>
        ),
    },
    {
        id: 'dashboard',
        title: 'Dashboard',
        icon: 'dashboard',
        body: (
            <>
                <p>The first page after sign-in. It shows the key figures for your network, the status of each NAS, recent authentication rejects and the top users this month. The figures refresh while the page is open.</p>
                <p>Use the rejects list to spot customers with a wrong password or an expired service before they call.</p>
            </>
        ),
    },
    {
        id: 'clients',
        title: 'Clients',
        icon: 'clients',
        body: (
            <>
                <p>Each client is one internet account (a PPPoE username) with contact details, a service plan, an end date and optional restrictions.</p>
                <h3>Add a client</h3>
                <Steps>
                    <li>Open <Link href="/clients">Clients</Link> and choose <strong>New client</strong>.</li>
                    <li><strong>Identity and contact</strong>: name, phone and email. Phone and email are used for alerts and invoices; tick <strong>SMS alert</strong> / <strong>Email alert</strong> only if the customer agreed to messages.</li>
                    <li><strong>Credentials</strong>: the PPPoE username and password the router uses to log in.</li>
                    <li><strong>Service and status</strong>: the plan, the service end date and, only when needed, a static IP pool and address.</li>
                    <li><strong>Access restrictions</strong> (optional): lock the account to one NAS or one MAC address.</li>
                    <li>Save. The account can connect immediately.</li>
                </Steps>
                <h3>Working with a client</h3>
                <ul>
                    <li><strong>Overview</strong>: current session, recent sessions, usage per day, invoices and payments.</li>
                    <li><strong>Diagnostics</strong>: why a customer cannot connect: account state, plan, last login attempts and replies, open sessions and queued router changes.</li>
                    <li><strong>Disconnect</strong> ends the current session; the customer can reconnect straight away. <strong>Suspend</strong> blocks the account until the restriction is lifted. A payment never clears a manual or security suspension.</li>
                    <li><strong>Reveal saved password</strong> shows the PPPoE password once; each reveal is recorded in the audit log.</li>
                    <li><strong>Documents</strong>: contracts and IDs attached to the customer; they are included in backups.</li>
                    <li>From the list you can search, export to CSV and apply bulk actions to selected clients.</li>
                </ul>
                <Note>When a service end date passes, the expiry job moves the customer to the plan's <em>expired</em> group, so the router gives them the expired-service behavior (for example a payment page) instead of normal access.</Note>
            </>
        ),
    },
    {
        id: 'services',
        title: 'Services and IP pools',
        icon: 'services',
        body: (
            <>
                <p>A service plan is a RADIUS group: speed, price, billing mode and what happens after expiry. Clients on the plan receive its attributes when they log in.</p>
                <Steps>
                    <li>Open <Link href="/services">Services</Link> → <strong>New plan</strong>.</li>
                    <li>Set the name, speed and price, and choose the <strong>expired service</strong> group customers move to when their end date passes.</li>
                    <li>Use <strong>Custom group attributes</strong> only for vendor-specific settings the plan fields do not cover.</li>
                </Steps>
                <h3>Static IP pools</h3>
                <p>Under <Link href="/services/ip-pools">Static IP pools</Link>, list the addresses you sell, one per line, as ranges (<code>192.0.2.10-20</code>) or small CIDRs (<code>192.0.2.0/28</code>), with a monthly price. A client form then offers only the free addresses of the chosen pool.</p>
                <p>A plan or pool that clients still use cannot be deleted; move or archive the clients first.</p>
            </>
        ),
    },
    {
        id: 'nas',
        title: 'NAS devices',
        icon: 'server',
        body: (
            <>
                <p>A NAS is a router (BRAS) that sends customer logins to RADIUS. RADIUS only answers NAS devices listed here.</p>
                <p><strong>MikroTik RouterOS</strong> is tested end to end (login, IP, speed limits, disconnect). <strong>Cisco IOS XE</strong> is marked <strong>Beta</strong>: it follows Cisco's documentation but has not been tested on a real router yet, so check logins and disconnects carefully before relying on it.</p>
                <h3>Add a NAS</h3>
                <Steps>
                    <li>Open <Link href="/nas">NAS Devices</Link> → <strong>New NAS device</strong>.</li>
                    <li>Enter a name, the router's IP address as RADIUS sees it (for example <code>192.0.2.1</code>), its platform and the <strong>RADIUS shared secret</strong>. Use the same secret in the router's RADIUS client settings.</li>
                    <li>Leave <strong>Disconnect sessions with RADIUS (CoA)</strong> on (port 3799 for MikroTik, often 1700 for Cisco).</li>
                    <li>Save, then open the NAS again for the optional <strong>API</strong> and <strong>SNMP monitoring</strong>.</li>
                </Steps>
                <h3>Disconnecting sessions</h3>
                <p>The <strong>Disconnect</strong> button on a customer sends a RADIUS <strong>Disconnect-Request (CoA)</strong> to the router, signed with the shared secret, and reports the router's answer straight away. No API login is needed. On MikroTik, allow it once:</p>
                <p><code>/radius incoming set accept=yes port=3799</code></p>
                <p>RouterOS accepts disconnects only from the RADIUS servers listed under <code>/radius</code>, with the right secret.</p>
                <p>On MikroTik you can instead switch on the <strong>API</strong> tab (a dedicated RouterOS user with read, write and api rights, over api-ssl on port 8729 with a certificate this server trusts). While the API is on, disconnects use it instead of CoA.</p>
                <h3>"Waiting for FreeRADIUS restart"</h3>
                <p>Changing a NAS address, name, type, shared secret or switching it on or off raises its <em>config revision</em>. FreeRADIUS reads the NAS list only when it starts, so such a change takes effect after a FreeRADIUS restart (<a href="#monitoring">Monitoring → RADIUS server → Restart</a>). Until then the NAS shows <strong>waiting for FreeRADIUS restart</strong> and the portal does not send disconnects or speed changes to it.</p>
                <p>Within a minute after FreeRADIUS has started again, the portal records the change as in effect by itself (FreeRADIUS does not start with a broken NAS list). CoA, API and SNMP settings are used by the portal alone and apply at once, without a restart.</p>
                <h3>SNMP</h3>
                <p>With SNMP (v2c read-only community, or v3 with authentication and optional AES privacy), the poller records CPU, memory, temperature and interface traffic every minute for the Monitoring graphs. Only read requests are sent. Without SNMP the NAS is checked by ping.</p>
            </>
        ),
    },
    {
        id: 'monitoring',
        title: 'Monitoring',
        icon: 'activity',
        body: (
            <>
                <p><Link href="/monitoring">Monitoring</Link> shows the RADIUS server and every NAS on one page, refreshing by itself. Red values need attention: CPU above 90%, memory above 95%, disk above 90%, a NAS that stopped answering, or a stuck background job.</p>
                <h3>Network tables</h3>
                <p>On a NAS with SNMP set up, its monitor page (<strong>Monitor</strong> on its card) has <strong>Network tables</strong>: IP addresses, ARP, MAC table, BGP peers and OSPF neighbours. Each is read live from the router over SNMP (read-only) when you open its tab; <strong>Refresh</strong> reads it again, the filter narrows the rows, and the table can be exported as CSV. A device that does not publish a table over SNMP shows it empty with a note (for example, RouterOS may not publish OSPF).</p>
                <h3>RADIUS server page</h3>
                <ul>
                    <li><strong>Services</strong>: FreeRADIUS, MariaDB, Nginx, PHP-FPM and Cron, each with its state and how long it has been running (<em>up 4d 6h 37m</em>). The time restarts from zero after every restart.</li>
                    <li><strong>System</strong>: uptime, load, memory, disk and temperatures (a virtual machine usually has no sensors).</li>
                    <li><strong>History</strong>: CPU, memory and traffic graphs by time range.</li>
                </ul>
                <h3>Restarting a service</h3>
                <p>Superadmins see a <strong>Restart</strong> button next to FreeRADIUS, MariaDB, Nginx and PHP-FPM.</p>
                <ul>
                    <li>The configuration is checked first; if the check fails nothing is restarted and the error is shown, so a typo cannot leave the service down.</li>
                    <li>The restart starts two seconds after you confirm, so the page can answer first. The status updates within about 15 seconds.</li>
                    <li>FreeRADIUS: logins pause for a few seconds; connected customers normally stay online. MariaDB: the portal and RADIUS logins fail for a few seconds (you type <code>restart</code> to confirm). Nginx / PHP-FPM: the portal is unreachable for a few seconds.</li>
                    <li>Every restart and failed attempt is written to the audit log.</li>
                </ul>
                <Note>The installer (<code>install.sh</code>) sets this up: a small helper and a sudo rule that let the web server restart exactly these four services and nothing else. To set it up again by hand, as root: <code>sudo bash ops/radiusone-postinstall</code> from the app directory.</Note>
                <h3>NAS monitor</h3>
                <p>Each NAS has its own page with live health, the busiest interfaces, online sessions, history graphs and the SNMP OIDs used for its platform. <strong>Poll now</strong> records a sample immediately instead of waiting for the next minute.</p>
                <h3 id="jobs">Background jobs</h3>
                <p>The bottom of Monitoring shows whether each scheduled job is running. A job that is not running leaves its feature switched off. See <a href="#server">Server operations</a> for the schedule.</p>
            </>
        ),
    },
    {
        id: 'operations',
        title: 'Network operations',
        icon: 'signal',
        body: (
            <>
                <p><Link href="/operations">Network operations</Link> lists the latest router changes the portal queued, for example speed changes after a plan change, or disconnects. Each shows its state and the router's answer.</p>
                <p><strong>Reconcile / retry</strong> sends a failed change again after you fixed the cause (router unreachable, wrong API login). Changes are sent by the network worker; while it is not enabled, they wait in the queue and Monitoring shows the worker as not sending.</p>
            </>
        ),
    },
    {
        id: 'billing',
        title: 'Billing',
        icon: 'billing',
        body: (
            <>
                <p><Link href="/billing">Billing</Link> holds invoices and payments. The cards at the top show what is still to collect, what is overdue and what came in this month; click one to see those invoices. The tabs above the list filter by status, with a count on each.</p>
                <Steps>
                    <li><strong>New invoice</strong>: choose the customer, the service plan (or no internet charge for static-IP-only invoices), the optional static IP add-on, the billing period and the issue date (discount, tax and notes are under the fold). The summary on the right shows the total and the due date; click <strong>Issue invoice</strong> to bill the customer, or <strong>Save as draft</strong> to finish it later.</li>
                    <li>Use <strong>Record payment</strong> on an unpaid invoice (or open it) (amount, date, method, reference). Full payment of a renewal extends the service end date.</li>
                    <li>A draft can be deleted; an issued invoice can only be cancelled, so the numbering stays complete.</li>
                </Steps>
                <p><strong>Recurring invoices</strong> are created every night at 03:15 (server time) for customers whose period is due, and overdue invoices are marked. Invoices and payment receipts can be downloaded as PDF, with your uploaded logo.</p>
            </>
        ),
    },
    {
        id: 'reports',
        title: 'Reports and logs',
        icon: 'reports',
        body: (
            <>
                <ul>
                    <li><Link href="/reports">Reports</Link>: accounting sessions started in a chosen range, open sessions, and session totals by start date.</li>
                    <li><Link href="/logs">Logs</Link>: the <strong>authentication attempts</strong> (every accepted and rejected login, filterable by reply) and the <strong>admin audit log</strong> (who changed what, and when).</li>
                </ul>
                <p>How long login and session records are kept is set under <a href="#retention">Data retention</a>.</p>
            </>
        ),
    },
    {
        id: 'profile',
        title: 'Your profile',
        icon: 'user',
        body: (
            <>
                <p>Click your name at the top to open <Link href="/profile">your profile</Link>: name, email, language, password (at least 10 characters) and two-factor authentication.</p>
                <Steps>
                    <li><strong>Set up authenticator</strong>, then scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password and similar).</li>
                    <li>Enter the current 6-digit code to confirm. From then on, sign-in asks for a code.</li>
                </Steps>
            </>
        ),
    },
    {
        id: 'users',
        title: 'Settings: operators',
        icon: 'clients',
        body: (
            <>
                <p>Under <Link href="/settings?tab=users">Settings → Users</Link>, superadmins add operators, choose their role, disable accounts and turn on mandatory two-factor authentication. A disabled operator cannot sign in; their history stays in the audit log. Give each person their own account and the smallest role that fits their work.</p>
            </>
        ),
    },
    {
        id: 'gateways',
        title: 'Settings: email and SMS gateways',
        icon: 'mail',
        body: (
            <>
                <p>Customer messages and admin alerts are sent through the gateways under <Link href="/settings?tab=notifications&view=gateways">Settings → Notifications → Gateways</Link>.</p>
                <h3>Email</h3>
                <Table
                    head={['Provider', 'What you need']}
                    rows={[
                        ['Custom SMTP', 'Host, port, encryption (TLS/SSL), username and password from your mail provider.'],
                        ['Mailgun', 'Sending domain, API key and region (US or EU).'],
                    ]}
                />
                <p>Set a <strong>from email</strong> your provider allows (for example <code>noc@example.com</code>) Emails go out under your company name from <em>Settings → Branding</em> (RadiusOne when none is set); invoice and receipt PDFs and the brand name in SMS texts use the same name.</p>
                <h3>SMS</h3>
                <Table
                    head={['Provider', 'What you need']}
                    rows={[
                        ['Twilio', 'Account SID, auth token and a sender number or messaging service SID.'],
                        ['Infobip', 'Your base URL, an API key (portal → Developer tools → API keys) and a registered sender.'],
                        ['Custom HTTP', 'The provider URL with {phone} and {message} placeholders (or a form body for POST), and optionally the text a successful answer contains.'],
                    ]}
                />
                <p>The <strong>default country code</strong> turns local numbers into international ones (for example <code>0691234567</code> becomes <code>+355691234567</code>). Leave it blank if every saved number already has its country code.</p>
                <p>After saving, use <strong>Send test</strong> on each gateway. "Accepted" means the provider took the message; check that it arrives.</p>
                <Note>Gateway passwords, tokens and keys are stored encrypted and are never shown again. Leave a password field empty to keep the saved value.</Note>
            </>
        ),
    },
    {
        id: 'customer-messages',
        title: 'Settings: customer messages',
        icon: 'message',
        body: (
            <>
                <ul>
                    <li><strong>Templates</strong>: <em>Welcome</em> (sent once after a new customer's first verified activation), <em>Service extended</em> (sent when a saved renewal moves the end date forward) and <em>Custom</em> (your starting point for bulk messages). Turn on Email and/or SMS per template.</li>
                    <li>Personalize with <code>{'{name}'}</code>, <code>{'{username}'}</code>, <code>{'{service}'}</code>, <code>{'{expiry}'}</code>, <code>{'{app_name}'}</code>, <code>{'{phone}'}</code> and <code>{'{email}'}</code>.</li>
                    <li><strong>Send bulk message</strong>: choose Email or SMS, select customers (or all active customers, up to 1,000), write the message and <strong>review</strong> it. Nothing is sent until you confirm the preview; the preview expires after 30 minutes.</li>
                    <li><strong>History</strong>: every message with its status. "Uncertain" needs a check with the provider and is not retried automatically.</li>
                </ul>
                <p>Customers whose Email or SMS alert flag is off never receive messages on that channel. Queued messages are sent every minute.</p>
            </>
        ),
    },
    {
        id: 'admin-alerts',
        title: 'Settings: admin alerts and daily report',
        icon: 'shield',
        body: (
            <>
                <p>Admin alerts tell your team when something on the server or network needs attention, and send a daily report. Set them up under <Link href="/settings?tab=notifications&view=admin">Settings → Notifications → Admin alerts</Link>.</p>
                <h3>Set up</h3>
                <Steps>
                    <li>Set up the Email gateway (and SMS, if you want text alerts). Telegram is set up on the same page, see below.</li>
                    <li>Tick <strong>Send admin alerts and reports</strong> and add the recipients: email addresses and/or phone numbers, separated by commas.</li>
                    <li>Choose what to watch and the channels for alerts.</li>
                    <li>Optionally turn on the <strong>daily report</strong> and its time (server time).</li>
                    <li>Save, then press <strong>Send test alert</strong> and <strong>Send report now</strong>.</li>
                </Steps>
                <h3>How alerts work</h3>
                <Table
                    head={['Check', 'Alerts when']}
                    rows={[
                        ['Server services', 'FreeRADIUS, MariaDB, Nginx, PHP-FPM or Cron is not running.'],
                        ['NAS', 'A NAS stops answering ping, or its SNMP data is older than the stale limit. NAS devices switched off for RADIUS are ignored.'],
                        ['Resources', 'Disk / is 90% full or more, or memory is 95% used or more.'],
                        ['Background jobs', 'A job is stuck (for example router changes waiting with nothing being sent).'],
                    ]}
                />
                <ul>
                    <li>Checks run every minute. A problem must still be there on the next check before it alerts, so a restart does not wake anyone.</li>
                    <li>When it clears you get a <strong>RESOLVED</strong> message with how long it lasted.</li>
                    <li>One message per check lists every new and resolved problem, plus those still open.</li>
                    <li><strong>Recent alerts</strong> shows the last 30 alerts, reports and tests with who received them and any provider error. One failing recipient does not stop the others.</li>
                </ul>
                <Note tone="warning">If MariaDB itself is down, the alert job cannot read its settings and no alert is sent. You will see it on Monitoring, and in the next report once the database is back.</Note>
                <h3>Daily report</h3>
                <p>Sent by email (and Telegram when chosen, never by SMS) at the chosen time (server time): open problems, server uptime and load, each service with its uptime, RADIUS logins accepted/rejected in the last 24 hours, every NAS with status and online users, customers (total, active, expired, expiring within 7 days), background jobs, and the alerts of the last 24 hours.</p>
                <h3 id="telegram">Telegram</h3>
                <p>Telegram is used only for admin alerts and the report, never for customer messages.</p>
                <Steps>
                    <li>In Telegram, open <strong>@BotFather</strong>, send <code>/newbot</code>, choose a name and a username ending in <em>bot</em>, and copy the token it gives you (it looks like <code>123456789:ABC…</code>).</li>
                    <li>Send any message to your new bot. For a team group, add the bot to the group and write a message there. For a channel, add the bot as an administrator.</li>
                    <li>In <strong>Admin alerts → Telegram</strong>, paste the token and save. The token is stored encrypted and never shown again.</li>
                    <li>Press <strong>Find chat IDs</strong>. It lists the chats that wrote to the bot, for example <code>123456789 · Name (private)</code> or <code>-1001234567890 · Team (group)</code>.</li>
                    <li>Copy the numbers into <strong>Telegram chat IDs</strong> (separated by commas; <code>@channelname</code> also works for public channels).</li>
                    <li>Tick <strong>Telegram</strong> under <em>Send alerts by</em>, and optionally <strong>Also send the daily report to Telegram</strong>. Save and press <strong>Send test alert</strong>.</li>
                </Steps>
                <p>If a test fails, Recent alerts shows Telegram's reason: <em>chat not found</em> (wrong ID, or nobody wrote to the bot yet), <em>bot was blocked by the user</em>, or <em>Unauthorized</em> (wrong or revoked token; create a new one with <code>/token</code> in @BotFather). To stop Telegram, untick it, or tick <strong>Remove the saved bot token</strong>.</p>
            </>
        ),
    },
    {
        id: 'branding',
        title: 'Settings: branding',
        icon: 'image',
        body: (
            <p>Under <Link href="/settings?tab=branding">Settings → Branding</Link>, upload a PNG, JPEG or WebP logo (up to 2 MB) or link one hosted on your website over HTTPS. The logo appears in the sidebar and on the sign-in page. Only an uploaded logo also appears on PDF invoices.</p>
        ),
    },
    {
        id: 'backups',
        title: 'Settings: backups',
        icon: 'server',
        body: (
            <>
                <p>Every night at 02:15 the database and customer documents are backed up on the server, encrypted, and uploaded to each enabled destination under <Link href="/settings?tab=backups">Settings → Backups</Link>.</p>
                <Steps>
                    <li>Set the <strong>backup passphrase</strong> (at least 12 characters). Store it somewhere safe outside this server: without it no backup can be opened. Changing it affects only new backups.</li>
                    <li>Choose how long <strong>local copies</strong> stay on the server. The newest three of each kind are always kept.</li>
                    <li>Add destinations: <a href="#b2">Backblaze B2</a>, <a href="#r2">Cloudflare R2</a> (the bucket address and a key; for Backblaze the key alone; guides below), or <strong>My own server (SFTP)</strong> as <code>user@host</code> (add the shown public key to its authorized keys). Name, folder and copies to keep (default 7) are under <strong>More options</strong>. Then press <strong>Test</strong>.</li>
                    <li>The page shows the last run and the result per destination.</li>
                </Steps>
                <h3 id="b2">Backblaze B2 setup</h3>
                <p>About 10 minutes. 10 GB is free, then about 6 USD per TB per month. No app registration or approval is needed.</p>
                <Steps>
                    <li>Create an account at <a href="https://www.backblaze.com/sign-up/cloud-storage" target="_blank" rel="noreferrer">backblaze.com</a> (B2 Cloud Storage) and choose a region close to you, for example <em>EU Central</em>.</li>
                    <li><strong>Create a bucket</strong>: <em>B2 Cloud Storage → Buckets → Create a Bucket</em>. Give it a unique name (for example <code>isp-name-radius-backups</code>), set <strong>Files in Bucket</strong> to <strong>Private</strong>, leave encryption and object lock off, and create it.</li>
                    <li><strong>Create a key</strong>: <em>Application Keys → Add a New Application Key</em>. Name it (for example <em>radiusone</em>), allow access to <strong>this bucket only</strong>, type of access <strong>Read and Write</strong>, and create it.</li>
                    <li><strong>Copy both values now</strong>: <strong>keyID</strong> and <strong>applicationKey</strong>. The applicationKey is shown only once.</li>
                    <li><strong>In the portal</strong>: <em>Settings → Backups → Add destination</em>, <strong>Store backups in</strong> <strong>Backblaze B2</strong>. Paste the <strong>keyID</strong> and <strong>applicationKey</strong> and save: the bucket and its endpoint are found from the key. (If the key can reach several buckets, also paste the bucket address, for example <code>https://s3.eu-central-003.backblazeb2.com/my-bucket</code>.)</li>
                    <li>Press <strong>Test</strong>. It uploads, lists and deletes a small file.</li>
                </Steps>
                <Note>B2 keeps old versions of a file by default. The portal deletes its own old backups by name, so set the bucket's <strong>Lifecycle Settings</strong> to <em>Keep only the last version of the file</em>; otherwise deleted copies stay billable as hidden versions.</Note>

                <h3 id="r2">Cloudflare R2 setup</h3>
                <p>About 10 minutes. 10 GB is free and downloads (restores) are always free. A payment method is needed to enable R2, even on the free tier.</p>
                <Steps>
                    <li>Sign in at <a href="https://dash.cloudflare.com/" target="_blank" rel="noreferrer">dash.cloudflare.com</a> (a free account is enough) and open <strong>R2 Object Storage</strong>. Enable it the first time.</li>
                    <li><strong>Create a bucket</strong>: <em>R2 → Create bucket</em>. Name it (for example <code>radius-backups</code>), location <em>Automatic</em> (or a jurisdiction such as EU), storage class <em>Standard</em>, and create it. Leave public access off.</li>
                    <li><strong>Copy the bucket address</strong>: in the bucket, <em>Settings → S3 API</em> shows <code>https://&lt;account id&gt;.r2.cloudflarestorage.com/&lt;bucket&gt;</code>. Copy it whole.</li>
                    <li><strong>Create a token</strong>: <em>R2 → Manage API tokens → Create API token</em> (an <em>Account</em> token). Permission <strong>Object Read &amp; Write</strong>, apply to <strong>this bucket only</strong>, no expiry, and create it.</li>
                    <li><strong>Copy both values now</strong>: <strong>Access Key ID</strong> and <strong>Secret Access Key</strong> (shown only once). The other token value is not needed.</li>
                    <li><strong>In the portal</strong>: <em>Settings → Backups → Add destination</em>, <strong>Store backups in</strong> <strong>Cloudflare R2</strong>. Paste the bucket address and both keys, and save.</li>
                    <li>Press <strong>Test</strong>.</li>
                </Steps>

                <h3>Backup storage problems</h3>
                <Table
                    head={['What you see', 'Cause and fix']}
                    rows={[
                        ['InvalidAccessKeyId or SignatureDoesNotMatch', 'The access key ID or secret is wrong, or was deleted at the provider. Paste a current pair (the secret field keeps the saved one when left empty).'],
                        ['AccessDenied', 'The key is limited to another bucket, or is read-only. Give it read and write access to this bucket.'],
                        ['NoSuchBucket', 'The bucket address does not match the bucket (wrong name, region or account). Paste it again from the provider.'],
                        ['Could not connect / timed out', 'Check the bucket address and that this server can reach the internet on port 443.'],
                        ['Storage keeps growing on B2', 'Set Lifecycle Settings to keep only the last version (see the note above).'],
                    ]}
                />
                <h3>On the server</h3>
                <ul>
                    <li>Run a backup now: <code>sudo RADIUSONE_OPERATOR_ENV_FILE=/etc/radiusone/backup.env radiusone-root-artisan radiusone:backup</code></li>
                    <li>Open a downloaded copy: <code>sudo radiusone-root-artisan radiusone:backup-decrypt &lt;file.enc&gt;</code> (asks for the passphrase).</li>
                    <li>Restore the database: <code>sudo RADIUSONE_OPERATOR_ENV_FILE=/etc/radiusone/migration.env radiusone-root-artisan radiusone:restore &lt;file.sql.gz&gt;</code>. This replaces the current data; take a fresh backup first. As root it restores only a root-owned file from a folder only root can write.</li>
                    <li>The nightly job (02:15, as root) is in <code>/etc/cron.d/radiusone</code>, set up by the installer.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'retention',
        title: 'Settings: data retention',
        icon: 'logs',
        body: (
            <p>Under <Link href="/settings?tab=retention">Settings → Data retention</Link>, choose how long RADIUS session records (accounting) and login records are kept. Older records are deleted by the nightly cleanup at 03:45. The default keeps everything. Shorter periods keep the database fast, but reports cannot look further back than the period.</p>
        ),
    },
    {
        id: 'server',
        title: 'Server operations',
        icon: 'settings',
        body: (
            <>
                <p>One cron line runs the Laravel scheduler every minute as the web user. Each job keeps its own lock, so a slow run is never started twice. Logs are in <code>/var/log/radiusone/</code>.</p>
                <Table
                    head={['Job', 'When', 'What it does']}
                    rows={[
                        [<code>radiusone:poll-snmp</code>, 'Every minute', 'Records server and NAS samples for Monitoring.'],
                        [<code>radiusone:admin-alerts</code>, 'Every minute', 'Admin alerts and the daily report (off until enabled in Settings).'],
                        [<code>radiusone:process-customer-notifications</code>, 'Every minute', 'Sends queued customer email and SMS.'],
                        [<code>radiusone:sync-expired</code>, 'Every minute (RADIUSONE_SCHEDULE_EXPIRY_SYNC=true)', 'Expires services, moves expired customers to their expired plan and publishes changed access rules to RADIUS.'],
                        [<code>radiusone:process-network-operations</code>, 'Every minute (NETWORK_WORKER_ENABLED=true)', 'Applies those changes on the network: an online customer is disconnected (CoA or RouterOS API) so they reconnect with the new rules; an offline one gets them at the next login. A refused disconnect is retried five times, then shown under Network operations.'],
                        [<code>radiusone:mark-nas-applied --auto</code>, 'Every minute', 'Records NAS changes as in effect once FreeRADIUS has restarted.'],
                        [<code>radiusone:generate-billing</code>, '03:15', 'Creates recurring invoices and marks overdue ones.'],
                        [<code>radiusone:backup</code>, '02:15 (root cron)', 'Database and documents backup with encrypted remote copies.'],
                        [<code>radiusone:prune-job-runs</code>, '03:30', 'Removes old job history.'],
                        [<code>radiusone:prune-radius</code>, '03:45', 'Applies the data retention periods.'],
                    ]}
                />
                <h3>Useful commands</h3>
                <p>Run as root through <code>radiusone-root-artisan</code>, never as <code>sudo php artisan</code>: that would load cache files www-data can write.</p>
                <Table
                    head={['Command', 'Use']}
                    rows={[
                        [<code>sudo radiusone-root-artisan radiusone:job-status</code>, 'Recent job runs, schema state, network lag and the notification queue.'],
                        [<code>sudo radiusone-root-artisan radiusone:check-schema</code>, 'Lists database migrations a feature still needs.'],
                        [<code>sudo radiusone-root-artisan radiusone:migrate</code>, 'Applies pending database migrations.'],
                        [<code>sudo radiusone-root-artisan radiusone:mark-nas-applied</code>, 'Records that a NAS change is active in FreeRADIUS (see NAS devices).'],
                        [<code>sudo radiusone-root-artisan radiusone:encrypt-secrets</code>, 'Encrypts or re-keys stored credentials.'],
                        [<code>sudo radiusone-root-artisan schedule:list</code>, 'Shows every scheduled job and when it runs next.'],
                    ]}
                />
                <h3>Installing and setup</h3>
                <ul>
                    <li><code>sudo bash install.sh</code>: installs and configures everything on a fresh Ubuntu 24.04 server (see INSTALL.md). Running it again repairs the setup and keeps all data.</li>
                    <li><code>sudo bash ops/radiusone-postinstall</code>: re-installs only the helpers behind the Restart, time zone, Back up now and Software update buttons.</li>
                    <li><strong>Updates</strong>: <em>Settings → Overview → Software update</em> checks GitHub for a newer release and installs it (database backup first, a few minutes of maintenance, automatic roll-back if a step fails). On the server: <code>sudo radiusone-update</code>. Log: <code>/var/lib/radiusone/update-status/update.log</code>.</li>
                    <li><strong>Servers installed before 2.3</strong> (the app in <code>/opt/freeradius-web-laravel</code>): after installing 2.3, <em>Finish moving to RadiusOne</em> moves the app to <code>/opt/radiusone</code> and renames the folders, database accounts and server files; the old paths stay as links. Preview without changing anything: <code>sudo bash ops/radiusone-upgrade-2.3 --dry-run</code>.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'troubleshooting',
        title: 'Troubleshooting',
        icon: 'activity',
        body: (
            <Table
                head={['Symptom', 'What to check']}
                rows={[
                    ['A customer cannot connect', 'Clients → the customer → Diagnostics: account state, end date, last login replies. Then Logs → Authentication attempts for that username.'],
                    ['All customers on one router fail', 'Monitoring: is the NAS answering? Does its shared secret match the router? Is the NAS waiting for a RADIUS update (restart FreeRADIUS)?'],
                    ['A NAS shows "waiting for FreeRADIUS restart"', 'Restart FreeRADIUS (Monitoring → RADIUS server); the NAS shows Up to date within a minute.'],
                    ['Disconnect fails or times out', 'NAS → Device: CoA on and the right port; on MikroTik /radius incoming set accept=yes; the shared secret matches; the router clock is correct. With the API on: the API user and certificate.'],
                    ['Speed changes do nothing', 'Network operations: the router\'s answer.'],
                    ['Restart button says restarts are not enabled', 'Run sudo bash ops/radiusone-postinstall from the app directory (install.sh does this).'],
                    ['Messages are not arriving', 'Gateways: send a test. History / Recent alerts: the provider\'s reason.'],
                    ['Monitoring graphs are empty', 'Background jobs: is the Monitoring poller running? Is SNMP set up on the NAS?'],
                ]}
            />
        ),
    },
];

/** Where a heading should sit after a jump: just under the top bar (and the contents row on narrow screens). */
function stickyOffset(): number {
    const bar = document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0;
    const toc = document.querySelector<HTMLElement>('.docs-toc');
    const row = toc && getComputedStyle(toc).display === 'flex' ? toc.getBoundingClientRect().height : 0;
    return bar + row + 16;
}

/** The section being read: the last one whose top has passed the reading line; the last one at the very bottom. */
function sectionInView(): string {
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) return SECTIONS[SECTIONS.length - 1].id;
    const line = stickyOffset() + 24;
    let current = SECTIONS[0].id;
    for (const s of SECTIONS) {
        const el = document.getElementById(s.id);
        if (el && el.getBoundingClientRect().top <= line) current = s.id;
    }
    return current;
}

export default function Docs() {
    const [active, setActive] = useState(SECTIONS[0].id);
    const toc = useRef<HTMLElement>(null);
    useEffect(() => revealCurrent(toc.current), [active]);
    // After a jump the clicked section stays highlighted (one near the end may not reach the top) until the reader scrolls.
    const pinned = useRef<string | null>(null);

    const jump = useCallback((id: string, smooth: boolean) => {
        const el = document.getElementById(id);
        if (!el) return;
        const section = el.closest<HTMLElement>('.docs-section')?.id ?? id;
        window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - stickyOffset(), behavior: smooth ? 'smooth' : 'auto' });
        // Keep the Inertia history entry; only the hash changes, so the browser does not jump again.
        history.replaceState(history.state, '', `#${id}`);
        pinned.current = section;
        setActive(section);
    }, []);

    useEffect(() => {
        let frame = 0;
        const update = () => { frame = 0; if (!pinned.current) setActive(sectionInView()); };
        const onScroll = () => { if (!frame) frame = requestAnimationFrame(update); };
        const unpin = () => { pinned.current = null; };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        for (const type of ['wheel', 'touchstart', 'keydown', 'mousedown']) window.addEventListener(type, unpin, { passive: true });
        // Arriving with #section (e.g. from Settings → "Setup guide"): go there once the page has laid out.
        const hash = decodeURIComponent(window.location.hash.slice(1));
        const timer = window.setTimeout(() => (hash ? jump(hash, false) : update()), 50);
        return () => {
            window.clearTimeout(timer);
            if (frame) cancelAnimationFrame(frame);
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
            for (const type of ['wheel', 'touchstart', 'keydown', 'mousedown']) window.removeEventListener(type, unpin);
        };
    }, [jump]);

    // On narrow screens the contents are one scrolling row: keep the current entry in sight.
    useEffect(() => {
        const link = document.querySelector<HTMLElement>(`.docs-toc a[href="#${active}"]`);
        const toc = link?.parentElement;
        if (link && toc && getComputedStyle(toc).display === 'flex') {
            toc.scrollTo({ left: link.offsetLeft - toc.clientWidth / 2 + link.clientWidth / 2, behavior: 'smooth' });
        }
    }, [active]);

    // Every #link on the page (contents and links inside the text) scrolls with the right offset.
    const onClick = (event: MouseEvent<HTMLDivElement>) => {
        const link = (event.target as HTMLElement).closest('a');
        const href = link?.getAttribute('href') ?? '';
        if (!href.startsWith('#') || event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        jump(decodeURIComponent(href.slice(1)), true);
    };

    return (
        <AppLayout title="Documentation">
            <div className="docs" onClick={onClick}>
                <nav className="docs-toc" aria-label="Documentation contents" ref={toc}>
                    <strong>Contents</strong>
                    {SECTIONS.map((s) => (
                        <a key={s.id} href={`#${s.id}`} aria-current={active === s.id ? 'true' : undefined}><Icon name={s.icon} />{s.title}</a>
                    ))}
                </nav>
                <div className="docs-body">
                    <header className="docs-intro">
                        <h2>Administrator guide</h2>
                        <p>What each page does, how to set it up and how it works. Visible to superadmins only. Examples use placeholder addresses and IDs, never this server's real settings.</p>
                    </header>
                    {SECTIONS.map((s) => (
                        <section key={s.id} id={s.id} className="docs-section">
                            <h2><span className="account-section-icon"><Icon name={s.icon} /></span>{s.title}</h2>
                            {s.body}
                        </section>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
