import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { Sheet } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Check, Field, Select, Tag, TextArea } from '@/components/ui';
import { useShared } from '@/lib/auth';
import { bind } from '@/lib/form';
import { useServerTimezone } from '@/lib/clock';
import { SecretField, TemplateField } from '@/settings/fields';
import type { Bulk, EmailGateway, Notifications, Preview, SmsGateway, Template } from '@/settings/types';

const NOTICES = '/settings?tab=notifications';

/** Sends one real message through the saved gateway. */
function TestSend({ channel, ready }: { channel: 'email' | 'sms'; ready: boolean }) {
    const form = useForm({ to: '' });
    if (!ready) return <p className="span2 hint">Save and enable the gateway to send a test.</p>;
    return (
        <form className="span2 inline-form gateway-test" onSubmit={(e) => { e.preventDefault(); form.post(`/settings/notifications/${channel}-test`, { preserveScroll: true }); }}>
            <Field
                label={channel === 'email' ? 'Send a test email to' : 'Send a test SMS to'}
                name="to"
                type={channel === 'email' ? 'email' : 'tel'}
                required
                placeholder={channel === 'email' ? 'you@example.com' : '+355 69 123 4567'}
                value={form.data.to}
                onChange={(e) => form.setData('to', e.target.value)}
            />
            <button disabled={form.processing}>Send test</button>
        </form>
    );
}

function SmsGatewayForm({ g, providers, ready }: { g: SmsGateway; providers: Record<string, string>; ready: boolean }) {
    const form = useForm({
        sms_gateway_enabled: g.enabled === '1',
        sms_gateway_provider: g.provider,
        sms_gateway_country_code: g.country_code,
        sms_gateway_method: g.method,
        sms_gateway_url: g.url,
        sms_gateway_body: g.body,
        sms_gateway_success: g.success,
        sms_gateway_twilio_sid: g.twilio_sid,
        sms_gateway_twilio_token: '',
        sms_gateway_twilio_from: g.twilio_from,
        sms_gateway_infobip_base_url: g.infobip_base_url,
        sms_gateway_infobip_api_key: '',
        sms_gateway_infobip_from: g.infobip_from,
    });
    const provider = form.data.sms_gateway_provider;
    return (
        <Sheet title="SMS gateway" description="Choose your SMS provider and enter the details from its dashboard." icon="message" meta={g.enabled === '1' ? <Badge tone="success">gateway enabled</Badge> : <Badge tone="warning">gateway disabled</Badge>}>
            <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/settings/notifications/sms-gateway', { preserveScroll: true, onFinish: () => form.setData((d) => ({ ...d, sms_gateway_twilio_token: '', sms_gateway_infobip_api_key: '' })) }); }}>
                <Check className="check span2" label="Enable SMS gateway" name="sms_gateway_enabled" checked={form.data.sms_gateway_enabled} onChange={(e) => form.setData('sms_gateway_enabled', e.target.checked)} />
                <Select label="Provider" options={providers} {...bind(form, 'sms_gateway_provider')} />
                <Field label="Default country code" placeholder="355" hint="Local numbers such as 069… are sent as +355 69…. Leave blank if every saved number already includes its country code." {...bind(form, 'sms_gateway_country_code')} />
                {provider === 'twilio' && (
                    <>
                        <Field label="Account SID" placeholder="AC…" hint="Twilio Console → Account info." {...bind(form, 'sms_gateway_twilio_sid')} />
                        <SecretField label="Auth token" name="sms_gateway_twilio_token" saved={g.twilio_token_saved} value={form.data.sms_gateway_twilio_token} error={form.errors.sms_gateway_twilio_token} onChange={(v) => form.setData('sms_gateway_twilio_token', v)} />
                        <Field label="Sender" hint="Your Twilio number (+1…), a Messaging Service SID (MG…), or an alphanumeric sender ID where your country allows it." {...bind(form, 'sms_gateway_twilio_from')} />
                    </>
                )}
                {provider === 'infobip' && (
                    <>
                        <Field label="Base URL" placeholder="xxxxx.api.infobip.com" hint="Shown on the Infobip portal home page; it is specific to your account." {...bind(form, 'sms_gateway_infobip_base_url')} />
                        <SecretField label="API key" name="sms_gateway_infobip_api_key" saved={g.infobip_api_key_saved} value={form.data.sms_gateway_infobip_api_key} error={form.errors.sms_gateway_infobip_api_key} onChange={(v) => form.setData('sms_gateway_infobip_api_key', v)} hint="Infobip portal → Developer tools → API keys." />
                        <Field label="Sender" hint="A registered sender name or number." {...bind(form, 'sms_gateway_infobip_from')} />
                    </>
                )}
                {provider === 'custom' && (
                    <>
                        <Select label="HTTP method" options={{ GET: 'GET', POST: 'POST' }} {...bind(form, 'sms_gateway_method')} />
                        <TextArea label="Gateway URL template" hint="Include {phone} and {message} in the URL, or in the body for POST. Your administrator must approve the provider host before enabling SMS." {...bind(form, 'sms_gateway_url')} />
                        <TextArea label="POST body template" hint="Form-encoded fields, for example: to={phone}&text={message}. Used only with POST." {...bind(form, 'sms_gateway_body')} />
                        <Field label="Success text contains" hint="Optional. Leave blank to accept any HTTP 2xx response." {...bind(form, 'sms_gateway_success')} />
                    </>
                )}
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Save SMS gateway</button></div>
            </form>
            <TestSend channel="sms" ready={ready} />
        </Sheet>
    );
}

function EmailGatewayForm({ g, providers, ready }: { g: EmailGateway; providers: Record<string, string>; ready: boolean }) {
    const { appName } = useShared();
    const form = useForm({
        email_gateway_enabled: g.enabled === '1',
        email_gateway_provider: g.provider,
        email_gateway_transport: g.transport,
        email_gateway_host: g.host,
        email_gateway_port: g.port,
        email_gateway_encryption: g.encryption,
        email_gateway_timeout: g.timeout,
        email_gateway_username: g.username,
        email_gateway_password: '',
        email_gateway_from_email: g.from_email,
        email_gateway_reply_to: g.reply_to,
        email_gateway_mailgun_domain: g.mailgun_domain,
        email_gateway_mailgun_region: g.mailgun_region,
        email_gateway_mailgun_api_key: '',
    });
    const provider = form.data.email_gateway_provider;
    const clearSecrets = () => form.setData((d) => ({ ...d, email_gateway_password: '', email_gateway_mailgun_api_key: '' }));
    const secret = (label: string, key: 'email_gateway_password' | 'email_gateway_mailgun_api_key', saved: boolean, hint?: string) => (
        <SecretField label={label} name={key} saved={saved} value={form.data[key]} error={form.errors[key]} onChange={(v) => form.setData(key, v)} hint={hint} />
    );
    return (
        <Sheet title="Email gateway" description="Choose your email provider and the sender customers will recognize." icon="mail" meta={g.enabled === '1' ? <Badge tone="success">gateway enabled</Badge> : <Badge tone="warning">gateway disabled</Badge>}>
            <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/settings/notifications/email-gateway', { preserveScroll: true, onFinish: clearSecrets }); }}>
                <Check className="check span2" label="Enable email gateway" name="email_gateway_enabled" checked={form.data.email_gateway_enabled} onChange={(e) => form.setData('email_gateway_enabled', e.target.checked)} />
                <Select label="Provider" options={providers} {...bind(form, 'email_gateway_provider')} />
                {provider === 'mailgun' && (
                    <>
                        <Field label="Sending domain" placeholder="mg.example.com" {...bind(form, 'email_gateway_mailgun_domain')} />
                        <Select label="Region" options={{ us: 'US (api.mailgun.net)', eu: 'EU (api.eu.mailgun.net)' }} {...bind(form, 'email_gateway_mailgun_region')} />
                        {secret('API key', 'email_gateway_mailgun_api_key', g.mailgun_api_key_saved, 'Mailgun → API Security → sending or private API key.')}
                    </>
                )}
                {provider === 'smtp' && (
                    <>
                        <Select label="Transport" options={{ smtp: 'SMTP', mail: 'PHP mail()' }} {...bind(form, 'email_gateway_transport')} />
                        <Field label="SMTP host" {...bind(form, 'email_gateway_host')} />
                        <Field label="SMTP port" type="number" min={1} max={65535} {...bind(form, 'email_gateway_port')} />
                        <Select label="Encryption" options={{ tls: 'STARTTLS', ssl: 'SSL/TLS', none: 'None' }} {...bind(form, 'email_gateway_encryption')} />
                        <Field label="Timeout seconds" type="number" min={3} max={60} {...bind(form, 'email_gateway_timeout')} />
                        <Field label="SMTP username" {...bind(form, 'email_gateway_username')} />
                        {secret('SMTP password', 'email_gateway_password', g.password_saved)}
                    </>
                )}
                <h3 className="form-section-title">Sender</h3>
                <Field label="From email" type="email" hint={provider === 'mailgun' ? 'An address on your Mailgun sending domain.' : undefined} {...bind(form, 'email_gateway_from_email')} />
                <p className="span2 hint">Emails are sent as <strong>{appName}</strong>: the company name under <Link href="/settings?tab=branding">Branding</Link>.</p>
                <Field label="Reply-to email" type="email" {...bind(form, 'email_gateway_reply_to')} />
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Save email gateway</button></div>
            </form>
            <TestSend channel="email" ready={ready} />
        </Sheet>
    );
}

function GatewayPanel({ n }: { n: Notifications }) {
    if (n.gateway === 'sms' && n.sms) return <SmsGatewayForm g={n.sms} providers={n.providers ?? {}} ready={n.ready.sms} />;
    if (n.gateway === 'email' && n.email) return <EmailGatewayForm g={n.email} providers={n.providers ?? {}} ready={n.ready.email} />;
    return null;
}

function Gateways({ n }: { n: Notifications }) {
    const anyReady = n.ready.email || n.ready.sms;
    const channels: [key: 'email' | 'sms', label: string, copy: string, icon: string][] = [
        ['email', 'Email', 'Send from your own email address.', 'mail'],
        ['sms', 'SMS', 'Send short messages through your SMS provider.', 'message'],
    ];
    return (
        <>
            <div className="settings-section-intro"><h3>{anyReady ? 'Delivery channels' : '1. Connect a gateway'}</h3></div>
            <div className="notice-gateways">
                {channels.map(([key, label, copy, icon]) => (
                    <article className="notice-gateway" key={key}>
                        <span className="account-section-icon"><Icon name={icon} /></span>
                        <div><h3>{label}</h3><p>{copy}</p></div>
                        <Badge tone={n.ready[key] ? 'success' : ''}>{n.ready[key] ? 'Enabled' : 'Not enabled'}</Badge>
                        <Link className="button" href={`${NOTICES}&view=gateways&gateway=${key}`} preserveScroll>{n.ready[key] ? 'Manage' : 'Configure'} {label}</Link>
                    </article>
                ))}
            </div>
            {n.gateway ? <GatewayPanel key={n.gateway} n={n} /> : anyReady && (
                <div className="notice-callout">Your gateway is enabled. Choose what customers receive next. <Link href={`${NOTICES}&view=templates`}>Set up templates →</Link></div>
            )}
        </>
    );
}

const TEMPLATE_DESCRIPTIONS: Record<string, string> = {
    welcome: 'Sent once, after a new customer’s first verified service activation.',
    extended: 'Sent when a saved renewal or expiry change extends the service end date.',
    usage_warning: 'Sent once per cycle when a customer has used 80% of their plan’s data cap.',
    usage_reached: 'Sent once per cycle when a customer reaches their plan’s data cap.',
    custom: 'Your starting point for announcements and bulk messages.',
};

function TemplateEditor({ name, label, template, tokens, ready }: { name: string; label: string; template: Template; tokens: string[]; ready: Notifications['ready'] }) {
    const form = useForm({
        template: name,
        subject: template.subject,
        email_body: template.email_body,
        sms_body: template.sms_body,
        email_enabled: template.email_enabled === '1',
        sms_enabled: template.sms_enabled === '1',
    });
    const custom = name === 'custom';
    return (
        <Sheet
            title={label}
            description={custom ? 'Save a reusable message. You can edit it for each bulk send.' : 'Choose a channel and customize the message.'}
            icon="mail"
            meta={<Badge>{custom ? 'Manual bulk' : 'Automatic'}</Badge>}
        >
            <form className="form-grid notice-editor" onSubmit={(event) => { event.preventDefault(); form.post('/settings/notifications/templates', { preserveScroll: true }); }}>
                {!custom && (
                    <div className="span2 notice-callout">
                        {name === 'welcome'
                            ? 'Automatically sends once after first verified activation. Existing customers do not receive a welcome message.'
                            : 'Automatically sends after a saved change moves the service end date forward. Partial payments and unchanged dates do not send.'}
                    </div>
                )}
                {(['email', 'sms'] as const).map((channel) => {
                    const title = channel === 'email' ? 'Email' : 'SMS';
                    const enabledKey = `${channel}_enabled` as const;
                    const bodyKey = `${channel}_body` as const;
                    return (
                        <div className="span2 form-grid" key={channel}>
                            <div className="span2 notice-editor-channel">
                                <h3>{title}</h3>
                                {ready[channel] ? <Badge tone="success">Gateway enabled</Badge> : <Link href={`${NOTICES}&view=gateways&gateway=${channel}`}>Configure gateway</Link>}
                            </div>
                            {!custom && (
                                <Check className="span2 checkbox" label={`Send ${title} automatically`} name={enabledKey} checked={form.data[enabledKey]} disabled={!ready[channel]} onChange={(e) => form.setData(enabledKey, e.target.checked)} />
                            )}
                            {channel === 'email' && <Field label="Email subject" name="subject" required maxLength={200} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />}
                            <TextArea label={`${title} message`} name={bodyKey} value={form.data[bodyKey]} onChange={(e) => form.setData(bodyKey, e.target.value)} />
                        </div>
                    );
                })}
                <p className="span2 settings-template-help">Personalize with: {tokens.map((t) => `{${t}}`).join(', ')}.</p>
                <div className="span2 form-actions">
                    <button className="primary" disabled={form.processing}>Save template</button>
                    <Link className="button" href={`${NOTICES}&view=templates`}>Cancel</Link>
                </div>
            </form>
        </Sheet>
    );
}

function Templates({ n }: { n: Notifications }) {
    const templates = n.templates ?? {};
    const labels = n.labels ?? {};
    if (n.template && templates[n.template]) {
        return <TemplateEditor key={n.template} name={n.template} label={labels[n.template]} template={templates[n.template]} tokens={n.tokens ?? []} ready={n.ready} />;
    }
    return (
        <>
            <div className="settings-section-intro"><h3>Templates</h3></div>
            <div className="notice-template-list">
                {Object.entries(labels).map(([key, label]) => {
                    const t = templates[key];
                    const channels = (['email', 'sms'] as const).filter((ch) => t?.[`${ch}_enabled`] === '1' && n.ready[ch]).map((ch) => (ch === 'email' ? 'Email' : 'SMS'));
                    return (
                        <article className="notice-template" key={key}>
                            <span className="account-section-icon"><Icon name={key === 'custom' ? 'message' : key === 'welcome' ? 'clients' : 'calendar'} /></span>
                            <div className="notice-template-copy">
                                <div className="notice-template-title"><h3>{label}</h3><Badge>{key === 'custom' ? 'Manual bulk' : 'Automatic'}</Badge></div>
                                <p>{TEMPLATE_DESCRIPTIONS[key]}</p>
                                <span className="notice-template-status">
                                    {key === 'custom' ? 'Review recipients and content before every send.' : channels.length ? <><span className="notice-dot" /> On · {channels.join(' + ')}</> : 'Off · Choose a channel to enable'}
                                </span>
                            </div>
                            <Link className="button" href={`${NOTICES}&view=templates&template=${key}`}>Edit template</Link>
                        </article>
                    );
                })}
            </div>
        </>
    );
}

function BulkComposer({ bulk, ready }: { bulk: Bulk; ready: Notifications['ready'] }) {
    const form = useForm({
        channel: bulk.channel,
        audience: bulk.audience,
        recipients: bulk.selected.map(String),
        subject: bulk.subject,
        body: bulk.body,
    });
    const [q, setQ] = useState(bulk.q);
    const toggle = (id: string, on: boolean) => form.setData('recipients', on ? [...form.data.recipients, id] : form.data.recipients.filter((r) => r !== id));
    return (
        <Sheet title="Send bulk message" description="Choose your recipients, write your message, then review before sending." icon="message" meta={<Badge>Custom</Badge>}>
            <div className="notice-channel-switch">
                <span>Send by</span>
                {(['email', 'sms'] as const).filter((ch) => ready[ch]).map((ch) => (
                    <Link key={ch} className={`button${ch === bulk.channel ? ' active' : ''}`} aria-current={ch === bulk.channel ? 'true' : undefined} href={`${NOTICES}&view=bulk&send_channel=${ch}`}>
                        {ch === 'email' ? 'Email' : 'SMS'}
                    </Link>
                ))}
            </div>
            <form
                className="notice-recipient-search"
                role="search"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/settings', { tab: 'notifications', view: 'bulk', send_channel: bulk.channel, recipient_q: q }, { preserveScroll: true });
                }}
            >
                <Field label="Find customers" name="recipient_q" type="search" value={q} onChange={(e) => setQ(e.target.value)} />
                <button>Search</button>
            </form>
            <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/settings/notifications/bulk-preview'); }}>
                <Select label="Recipients" name="audience" options={{ selected: 'Selected customers below', active: 'All active customers (up to 1,000)' }} value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value)} />
                <div className="span2 notice-recipient-list">
                    <DataTable
                        rows={bulk.rows}
                        rowKey={(r) => r.id}
                        perPage={200}
                        columns={[
                            {
                                header: 'Select',
                                cell: (r) => (
                                    <input type="checkbox" name="recipients[]" value={r.id} aria-label={`Select ${r.username}`} checked={form.data.recipients.includes(String(r.id))} onChange={(e) => toggle(String(r.id), e.target.checked)} />
                                ),
                            },
                            { header: 'Customer', cell: (r) => r.username },
                            { header: bulk.channel.toUpperCase(), cell: (r) => r.recipient },
                            { header: 'Eligibility', cell: (r) => r.eligibility },
                        ]}
                    />
                </div>
                {bulk.more && <p className="span2">Showing the first 200 customers. Search to narrow the list, or choose all active customers.</p>}
                {bulk.channel === 'email' && <Field label="Subject" name="subject" required maxLength={200} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />}
                <TextArea label="Message" name="body" hint="Personalize with {name}, {username}, {service}, {expiry}, {app_name}, {phone}, {email}." value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Review message →</button></div>
            </form>
        </Sheet>
    );
}

function BulkPreview({ preview }: { preview: Preview | null | undefined }) {
    if (!preview) {
        return <div className="notice-callout">Your preview expired. <Link href={`${NOTICES}&view=bulk`}>Compose a message</Link>.</div>;
    }
    return (
        <Sheet title="Review your message" description="Check the message and recipients before confirming." icon="mail" meta={<Badge>Nothing sent yet</Badge>}>
            <div className="notice-review-summary">
                <strong>{preview.eligible} recipients ready</strong>
                <span>{preview.items.length - preview.eligible} excluded · {preview.channel.toUpperCase()}</span>
            </div>
            <div className="notice-message-preview">
                <small>Preview for {preview.sample.username}</small>
                <h3>{preview.sample.subject}</h3>
                <pre className="notice-message">{preview.sample.body}</pre>
            </div>
            <details className="notice-recipient-review">
                <summary>Review all {preview.items.length} recipients and personalized messages</summary>
                <DataTable
                    rows={preview.items}
                    columns={[
                        { header: 'Customer', cell: (i) => i.username },
                        { header: 'Contact', cell: (i) => i.recipient },
                        { header: 'Eligibility', cell: (i) => i.reason || 'Ready' },
                        { header: 'Message', cell: (i) => <details><summary>View message</summary><strong>{i.subject}</strong><pre className="notice-message">{i.body}</pre></details> },
                    ]}
                />
            </details>
            <p className="notice-footnote">Messages are queued after confirmation. Preferences, contacts and gateway availability are checked again before sending. This preview expires after 30 minutes.</p>
            <div className="form-actions">
                <PostButton
                    action="/settings/notifications/bulk-confirm"
                    data={{ draft_token: preview.token }}
                    className="primary"
                    disabled={!preview.eligible}
                    label={`Confirm and queue ${preview.eligible} messages`}
                    preserveScroll={false}
                />
                <Link className="button" href={`${NOTICES}&view=bulk&edit=1`}>Back to compose</Link>
            </div>
        </Sheet>
    );
}

function History({ history }: { history: NonNullable<Notifications['history']> }) {
    return (
        <Sheet title="Message history" description="Accepted means the gateway accepted the message. It does not confirm delivery to the customer. Uncertain messages need provider review and are not automatically retried." icon="logs">
            {history.rows.length ? (
                <DataTable
                    rows={history.rows}
                    rowKey={(r) => r.id}
                    perPage={50}
                    columns={[
                        { header: 'Queued', cell: (r) => r.queued },
                        { header: 'Customer', cell: (r) => r.username },
                        { header: 'Template', cell: (r) => r.template },
                        { header: 'Channel', cell: (r) => r.channel },
                        { header: 'Contact', cell: (r) => r.recipient },
                        { header: 'Status', cell: (r) => <Tag tag={r.status} /> },
                        { header: 'Details', cell: (r) => r.reason },
                    ]}
                />
            ) : (
                <div className="settings-empty-state"><Icon name="mail" /><strong>No messages yet</strong><p>Automatic and bulk messages will appear here.</p></div>
            )}
            {history.older !== null && <Link className="button" href={`${NOTICES}&view=history&before=${history.older}`}>Older messages →</Link>}
            {history.before > 0 && <Link className="button" href={`${NOTICES}&view=history`}>Latest messages</Link>}
        </Sheet>
    );
}

function AdminAlerts({ admin, ready }: { admin: NonNullable<Notifications['admin']>; ready: Notifications['ready'] }) {
    const timeZone = useServerTimezone();
    const { telegram_token_saved: tokenSaved, ...saved } = admin.settings;
    const form = useForm({ ...saved, telegram_token: '', telegram_token_clear: false });
    const toggleCheck = (key: string, on: boolean) => form.setData('checks', on ? [...form.data.checks, key] : form.data.checks.filter((c) => c !== key));
    const gateway = (channel: 'email' | 'sms') => (ready[channel] ? null : <> · <Link href={`${NOTICES}&view=gateways&gateway=${channel}`}>set up the {channel === 'email' ? 'Email' : 'SMS'} gateway</Link></>);
    return (
        <>
            <Sheet title="Admin alerts" description="Tell the team when something on the server or network needs attention, and send a daily report. Uses the same Email and SMS gateways as customer messages." icon="shield" meta={<Badge tone={admin.settings.enabled ? 'success' : undefined}>{admin.settings.enabled ? 'On' : 'Off'}</Badge>}>
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/settings/notifications/admin', { preserveScroll: true }); }}>
                    <Check className="span2 checkbox" label={<strong>Send admin alerts and reports</strong>} name="enabled" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} />
                    <TextArea className="" label="Email recipients" name="emails" rows={2} hint="Separate addresses with commas." value={form.data.emails} onChange={(e) => form.setData('emails', e.target.value)} />
                    <TextArea className="" label="SMS recipients" name="phones" rows={2} hint="Phone numbers, separated with commas." value={form.data.phones} onChange={(e) => form.setData('phones', e.target.value)} />

                    <div className="span2 notice-editor-channel"><h3>Alerts</h3></div>
                    <fieldset className="span2 admin-alert-checks">
                        <legend>Alert me when</legend>
                        {Object.entries(admin.checks).map(([key, label]) => (
                            <Check key={key} className="checkbox" label={label} name="checks[]" value={key} checked={form.data.checks.includes(key)} onChange={(e) => toggleCheck(key, e.target.checked)} />
                        ))}
                    </fieldset>
                    <fieldset className="span2 admin-alert-checks">
                        <legend>Send alerts by</legend>
                        <Check className="checkbox" label={<>Email{gateway('email')}</>} name="alert_email" checked={form.data.alert_email} onChange={(e) => form.setData('alert_email', e.target.checked)} />
                        <Check className="checkbox" label={<>SMS{gateway('sms')}</>} name="alert_sms" checked={form.data.alert_sms} onChange={(e) => form.setData('alert_sms', e.target.checked)} />
                        <Check className="checkbox" label="Telegram (set up below)" name="alert_telegram" checked={form.data.alert_telegram} onChange={(e) => form.setData('alert_telegram', e.target.checked)} />
                    </fieldset>

                    <div className="span2 notice-editor-channel"><h3>Telegram</h3>{tokenSaved ? <Badge tone="success">Bot token saved</Badge> : <Badge>Not set up</Badge>}<Link href="/docs#telegram">Setup guide</Link></div>
                    <Field
                        label="Bot token"
                        name="telegram_token"
                        type="password"
                        autoComplete="off"
                        placeholder={tokenSaved ? 'Saved. Leave empty to keep it' : '123456789:ABC…'}
                        value={form.data.telegram_token}
                        onChange={(e) => form.setData('telegram_token', e.target.value)}
                    />
                    <Field label="Telegram chat IDs" name="telegram_chats" placeholder="123456789, -1001234567890" hint="People, groups or channels; separate with commas." value={form.data.telegram_chats} onChange={(e) => form.setData('telegram_chats', e.target.value)} />
                    {tokenSaved && <Check className="span2 checkbox" label="Remove the saved bot token" name="telegram_token_clear" checked={form.data.telegram_token_clear} onChange={(e) => form.setData('telegram_token_clear', e.target.checked)} />}
                    {tokenSaved && <div className="span2"><PostButton action="/settings/notifications/admin/telegram-chats" className="" label="Find chat IDs" /></div>}

                    <div className="span2 notice-editor-channel"><h3>Daily report</h3></div>
                    <Check className="span2 checkbox" label="Email a daily report: open problems, services and uptime, server load, RADIUS logins, NAS status, customers expiring soon and background jobs" name="report_enabled" checked={form.data.report_enabled} onChange={(e) => form.setData('report_enabled', e.target.checked)} />
                    <Check className="span2 checkbox" label="Also send the daily report to Telegram" name="report_telegram" checked={form.data.report_telegram} onChange={(e) => form.setData('report_telegram', e.target.checked)} />
                    <Field label="Send at" name="report_time" type="time" required value={form.data.report_time} onChange={(e) => form.setData('report_time', e.target.value)} hint={`Server time (${timeZone}).`} />

                    <details className="span2">
                        <summary>Message templates</summary>
                        <div className="form-grid template-fields">
                            {Object.entries(admin.templates).map(([key, t]) => (
                                <TemplateField
                                    key={key}
                                    label={t.label}
                                    name={`templates[${key}]`}
                                    value={form.data.templates[key] ?? ''}
                                    fallback={t.default}
                                    multiline={key === 'alert_body' || key === 'report_intro'}
                                    hint={`Placeholders: ${admin.placeholders[key.startsWith('report') ? 'report' : 'alert'].map((p) => `{${p}}`).join(' ')}`}
                                    error={(form.errors as Record<string, string>)[`templates.${key}`]}
                                    onChange={(v) => form.setData('templates', { ...form.data.templates, [key]: v })}
                                />
                            ))}
                        </div>
                    </details>

                    <div className="span2 form-actions">
                        <button className="primary" disabled={form.processing}>Save admin alerts</button>
                        <PostButton action="/settings/notifications/admin/test" className="" label="Send test alert" disabled={form.isDirty} title={form.isDirty ? 'Save first' : undefined} />
                        <PostButton action="/settings/notifications/admin/report" className="" label="Send report now" disabled={form.isDirty} title={form.isDirty ? 'Save first' : undefined} />
                    </div>
                </form>
            </Sheet>
            <Sheet title="Recent alerts" description="The last 30 alerts, reports and tests, with who received them." icon="logs">
                {admin.recent.length ? (
                    <DataTable
                        rows={admin.recent}
                        perPage={30}
                        columns={[
                            { header: 'Time', cell: (r) => r.at },
                            { header: 'Type', cell: (r) => <Tag tag={r.kind} /> },
                            { header: 'What', cell: (r) => r.text },
                            { header: 'Delivery', cell: (r) => r.sent },
                        ]}
                    />
                ) : (
                    <div className="settings-empty-state"><Icon name="shield" /><strong>No alerts yet</strong><p>Alerts, reports and tests will appear here.</p></div>
                )}
            </Sheet>
        </>
    );
}

export function NotificationsTab({ n }: { n: Notifications }) {
    const anyReady = n.ready.email || n.ready.sms;
    const views: [string, string][] = [['templates', 'Templates'], ['gateways', 'Gateways'], ['history', 'History'], ['admin', 'Admin alerts']];
    return (
        <div className="settings-notifications notice-workspace">
            <div className="notice-heading">
                <h2>Notifications</h2>
                {anyReady
                    ? <Link className="button primary" href={`${NOTICES}&view=bulk`}><Icon name="message" /> Send bulk message</Link>
                    : <span className="notice-setup-label">Start with a gateway</span>}
            </div>
            <nav className="notice-nav" aria-label="Notification settings">
                {views.map(([key, label]) => (
                    <Link key={key} href={`${NOTICES}&view=${key}`} aria-current={n.view === key ? 'page' : undefined} preserveScroll>{label}</Link>
                ))}
            </nav>
            {!anyReady && n.view !== 'gateways' && (
                <div className="notice-callout">Connect an Email or SMS gateway to start sending. <Link href={`${NOTICES}&view=gateways`}>Set up a gateway →</Link></div>
            )}
            {n.view === 'gateways' && <Gateways n={n} />}
            {n.view === 'templates' && <Templates n={n} />}
            {n.view === 'bulk' && n.bulk && <BulkComposer key={n.bulk.channel} bulk={n.bulk} ready={n.ready} />}
            {n.view === 'preview' && <BulkPreview preview={n.preview} />}
            {n.view === 'history' && n.history && <History history={n.history} />}
            {n.view === 'admin' && n.admin && <AdminAlerts admin={n.admin} ready={n.ready} />}
        </div>
    );
}
