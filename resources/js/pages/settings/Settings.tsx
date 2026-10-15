import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { DeleteButton, PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { RoleGrid, Sheet, SettingsLinkNav, SettingsRows } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Check, Empty, Field, Select, Tag, TextArea, type TagData } from '@/components/ui';
import AppLayout, { BrandLogo } from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Overview = { engine: TagData; rows: [string, string][] };
type Operator = { id: number; name: string; email: string; username: string; role: string; enabled: boolean; twoFactor: TagData; lastLogin: string; self: boolean };
type Branding = { custom: boolean; remote: boolean; url: string; hasSetting: boolean };
type Template = { subject: string; email_body: string; sms_body: string; email_enabled: string; sms_enabled: string };
type Bulk = { channel: 'email' | 'sms'; q: string; rows: { id: number; username: string; recipient: string; eligibility: string }[]; more: boolean; selected: number[]; audience: string; subject: string; body: string };
type PreviewItem = { username: string; recipient: string; reason: string; subject: string; body: string };
type Preview = { token: string; channel: string; eligible: number; items: PreviewItem[]; sample: PreviewItem };
type HistoryRow = { id: number; queued: string; username: string; template: string; channel: string; recipient: string; status: TagData; reason: string };
type Notifications = {
    view: 'templates' | 'gateways' | 'bulk' | 'preview' | 'history';
    ready: { email: boolean; sms: boolean };
    gateway?: 'email' | 'sms' | null;
    sms?: { enabled: string; method: string; url: string; body: string; success: string };
    email?: { enabled: string; transport: string; host: string; port: string; encryption: string; username: string; from_email: string; from_name: string; reply_to: string; timeout: string };
    labels?: Record<string, string>;
    tokens?: string[];
    templates?: Record<string, Template>;
    template?: string | null;
    bulk?: Bulk | null;
    preview?: Preview | null;
    history?: { before: number; older: number | null; rows: HistoryRow[] };
};

const NOTICES = '/settings?tab=notifications';

function Destination({ title, description, href, icon, status }: { title: string; description: string; href: string; icon: string; status: ReactNode }) {
    return (
        <Link className="settings-destination" href={href}>
            <span className="account-section-icon"><Icon name={icon} /></span>
            <h3>{title}</h3>
            <p>{description}</p>
            <span className="settings-destination-status">{status}</span>
            <span className="settings-destination-action">Configure <span aria-hidden="true">→</span></span>
        </Link>
    );
}

function OverviewTab({ overview, usersSummary, branding }: { overview: Overview; usersSummary: { enabled: number; total: number }; branding: Branding }) {
    return (
        <>
            <div className="settings-destinations">
                <Destination title="Users & access" description="Manage operators, roles, and two-factor authentication." href="/settings?tab=users" icon="clients" status={<Badge tone="success">{usersSummary.enabled} enabled</Badge>} />
                <Destination title="Notifications" description="Connect a gateway, customize templates and send customer updates." href="/settings?tab=notifications" icon="mail" status={<Badge>Email + SMS</Badge>} />
                <Destination title="Branding" description="Give your workspace and invoices a familiar identity." href="/settings?tab=branding" icon="image" status={<Badge>{branding.custom ? 'Custom logo' : 'Default logo'}</Badge>} />
            </div>
            <Sheet title="System information" description="Environment, operator access, and backup locations for this workspace." icon="server" meta={<Tag tag={overview.engine} />}>
                <SettingsRows rows={overview.rows} />
            </Sheet>
        </>
    );
}

function UsersTab({ users, usersSummary, roleDescriptions }: { users: Operator[] | null; usersSummary: { enabled: number; total: number }; roleDescriptions: { role: string; description: string }[] }) {
    const can = useCan();
    if (!users) {
        return (
            <Sheet title="Users" className="settings-users-card">
                <Empty>Ask a superadmin to change users, roles, or two-factor requirements.</Empty>
            </Sheet>
        );
    }
    return (
        <>
            <Sheet
                title="Workspace users"
                description="Manage who can sign in and what they can access."
                icon="clients"
                className="settings-users-card"
                meta={<>
                    <Badge tone={usersSummary.enabled > 0 ? 'success' : 'warning'}>{usersSummary.enabled}/{usersSummary.total} enabled</Badge>
                    <Link className="button primary" href="/settings/users/create"><Icon name="plus" />New user</Link>
                </>}
            >
                <DataTable
                    rows={users}
                    rowKey={(r) => r.id}
                    exportRows
                    columns={[
                        { header: 'Name', cell: (r) => r.name },
                        { header: 'Email', cell: (r) => r.email },
                        { header: 'Username', cell: (r) => r.username },
                        { header: 'Role', cell: (r) => <Badge>{r.role}</Badge> },
                        { header: 'Status', cell: (r) => (r.enabled ? <Badge tone="success">enabled</Badge> : <Badge tone="danger">disabled</Badge>) },
                        { header: '2FA', cell: (r) => <Tag tag={r.twoFactor} /> },
                        { header: 'Last login', cell: (r) => r.lastLogin },
                        {
                            header: 'Actions',
                            noExport: true,
                            cell: (r) => (
                                <>
                                    <Link href={`/settings/users/${r.id}/edit`}>Edit</Link>{' '}
                                    {r.self ? <span className="muted-action">You</span> : can('danger.delete') && (
                                        <DeleteButton
                                            action={`/settings/users/${r.id}/delete`}
                                            message={`${r.username} can no longer sign in. Their audit history is kept.`}
                                            typeToConfirm={r.username}
                                            title={`Delete operator ${r.username}?`}
                                        />
                                    )}
                                </>
                            ),
                        },
                    ]}
                />
            </Sheet>
            <Sheet title="Role access" description="A reference for assigning the right level of workspace access." icon="lock">
                <RoleGrid roles={roleDescriptions} />
            </Sheet>
        </>
    );
}

function LogoPreview({ src, label }: { src: string; label: string }) {
    const [status, setStatus] = useState('');
    useEffect(() => setStatus(src ? 'Loading preview…' : ''), [src]);
    if (!src) return null;
    return (
        <div className="logo-draft">
            <img src={src} alt={label} referrerPolicy="no-referrer" onLoad={() => setStatus('Preview ready.')} onError={() => setStatus('This image could not be loaded.')} />
            <span role="status">{status}</span>
        </div>
    );
}

function BrandingTab({ branding }: { branding: Branding }) {
    const upload = useForm<{ portal_logo: File | null }>({ portal_logo: null });
    const link = useForm({ portal_logo_url: branding.url });
    const [filePreview, setFilePreview] = useState('');
    const [urlPreview, setUrlPreview] = useState('');
    useEffect(() => () => { if (filePreview) URL.revokeObjectURL(filePreview); }, [filePreview]);

    return (
        <Sheet
            title="Workspace branding"
            description="Choose the logo your team and customers see."
            icon="image"
            className="branding-card"
            meta={<Badge tone={branding.custom ? 'success' : ''}>{branding.custom ? (branding.remote ? 'HTTPS logo' : 'Uploaded logo') : 'Default logo'}</Badge>}
        >
            <div className="branding-layout">
                <div className="branding-preview-panel">
                    <span className="workspace-eyebrow">YOUR WORKSPACE</span>
                    <div className="brand-preview-stage"><BrandLogo className="preview-logo" /><strong>RadiusWeb</strong><span>Client management</span></div>
                    <h3>One logo. A familiar workspace.</h3>
                    <p>Your logo appears on the sign-in screen and sidebar for everyone on your team.</p>
                    <p className="hint">Uploaded logos also appear on PDF invoices. Use an upload if you need your logo on invoices.</p>
                    {branding.hasSetting && (
                        <PostButton action="/settings/branding/logo/delete" label="Reset to default logo" confirm={{ message: 'Remove custom logo and use the default mark?', confirmLabel: 'Reset logo' }} />
                    )}
                </div>
                <div className="branding-methods">
                    <section className="branding-method">
                        <div className="branding-method-title"><span className="method-number">01</span><div><h3>Upload your logo</h3><p>Keep a copy in your workspace.</p></div></div>
                        <form
                            className="branding-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                upload.post('/settings/branding/logo', { forceFormData: true, preserveScroll: true, onSuccess: () => { upload.reset(); setFilePreview(''); } });
                            }}
                        >
                            <label className="logo-dropzone" htmlFor="portal-logo-file">
                                <Icon name="plus" />
                                <strong>Choose a logo file</strong>
                                <span>PNG, JPG or WebP · up to 2 MB</span>
                                <input
                                    id="portal-logo-file"
                                    name="portal_logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    required
                                    aria-describedby="logo-file-help"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0] ?? null;
                                        upload.setData('portal_logo', file);
                                        setFilePreview(file ? URL.createObjectURL(file) : '');
                                    }}
                                />
                            </label>
                            <small id="logo-file-help">A square or horizontal logo with a clear background works best.</small>
                            <LogoPreview src={filePreview} label="Selected logo preview" />
                            <div className="form-actions"><button className="primary" disabled={upload.processing}>Upload logo</button></div>
                        </form>
                    </section>
                    <section className="branding-method">
                        <div className="branding-method-title"><span className="method-number">02</span><div><h3>Use an image URL</h3><p>Connect a logo hosted on your website.</p></div></div>
                        <form className="branding-form" onSubmit={(event) => { event.preventDefault(); link.post('/settings/branding/logo/url', { preserveScroll: true }); }}>
                            <label htmlFor="portal-logo-url">
                                HTTPS image URL
                                <input
                                    id="portal-logo-url"
                                    type="url"
                                    name="portal_logo_url"
                                    placeholder="https://example.com/logo.png"
                                    maxLength={2048}
                                    pattern="[Hh][Tt][Tt][Pp][Ss]://.+"
                                    required
                                    aria-describedby="logo-url-help"
                                    value={link.data.portal_logo_url}
                                    onChange={(e) => link.setData('portal_logo_url', e.target.value)}
                                />
                            </label>
                            <small id="logo-url-help">Use a public, direct image link. The image stays hosted at this address.</small>
                            <LogoPreview src={urlPreview} label="URL logo preview" />
                            <div className="form-actions">
                                <button type="button" onClick={() => setUrlPreview(/^https:\/\/.+/i.test(link.data.portal_logo_url) ? link.data.portal_logo_url : '')}>Preview logo</button>
                                <button className="primary" disabled={link.processing}>Save logo URL</button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </Sheet>
    );
}

function GatewayPanel({ n }: { n: Notifications }) {
    const sms = useForm({
        sms_gateway_enabled: n.sms?.enabled === '1',
        sms_gateway_method: n.sms?.method ?? 'GET',
        sms_gateway_url: n.sms?.url ?? '',
        sms_gateway_body: n.sms?.body ?? '',
        sms_gateway_success: n.sms?.success ?? '',
    });
    const email = useForm({
        email_gateway_enabled: n.email?.enabled === '1',
        email_gateway_transport: n.email?.transport ?? 'smtp',
        email_gateway_host: n.email?.host ?? '',
        email_gateway_port: n.email?.port ?? '587',
        email_gateway_encryption: n.email?.encryption ?? 'tls',
        email_gateway_timeout: n.email?.timeout ?? '12',
        email_gateway_username: n.email?.username ?? '',
        email_gateway_password: '',
        email_gateway_from_email: n.email?.from_email ?? '',
        email_gateway_from_name: n.email?.from_name ?? '',
        email_gateway_reply_to: n.email?.reply_to ?? '',
    });
    if (n.gateway === 'sms' && n.sms) {
        return (
            <Sheet title="SMS gateway" description="Connect your SMS provider and define a successful response." icon="message" meta={n.sms.enabled === '1' ? <Badge tone="success">gateway enabled</Badge> : <Badge tone="warning">gateway disabled</Badge>}>
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); sms.post('/settings/notifications/sms-gateway', { preserveScroll: true }); }}>
                    <Check label="Enable SMS gateway" name="sms_gateway_enabled" checked={sms.data.sms_gateway_enabled} onChange={(e) => sms.setData('sms_gateway_enabled', e.target.checked)} />
                    <Select label="HTTP method" name="sms_gateway_method" options={{ GET: 'GET', POST: 'POST' }} value={sms.data.sms_gateway_method} onChange={(e) => sms.setData('sms_gateway_method', e.target.value)} />
                    <TextArea label="Gateway URL template" name="sms_gateway_url" hint="Include {phone} and {message} in the URL, or in the body for POST. Your administrator must approve the provider host before enabling SMS." value={sms.data.sms_gateway_url} onChange={(e) => sms.setData('sms_gateway_url', e.target.value)} />
                    <TextArea label="POST body template" name="sms_gateway_body" hint="Form-encoded fields, for example: to={phone}&text={message}. Used only with POST." value={sms.data.sms_gateway_body} onChange={(e) => sms.setData('sms_gateway_body', e.target.value)} />
                    <Field label="Success text contains" name="sms_gateway_success" hint="Optional. Leave blank to accept any HTTP 2xx response." value={sms.data.sms_gateway_success} onChange={(e) => sms.setData('sms_gateway_success', e.target.value)} />
                    <div className="span2 form-actions"><button className="primary" disabled={sms.processing}>Save SMS gateway</button></div>
                </form>
            </Sheet>
        );
    }
    if (n.gateway === 'email' && n.email) {
        const text = (label: string, key: keyof typeof email.data, props: Record<string, unknown> = {}) => (
            <Field label={label} name={key} value={String(email.data[key])} onChange={(e) => email.setData(key, e.target.value as never)} {...props} />
        );
        return (
            <Sheet title="Email gateway" description="Set your mail connection and the sender customers will recognize." icon="mail" meta={n.email.enabled === '1' ? <Badge tone="success">gateway enabled</Badge> : <Badge tone="warning">gateway disabled</Badge>}>
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); email.post('/settings/notifications/email-gateway', { preserveScroll: true, onFinish: () => email.setData('email_gateway_password', '') }); }}>
                    <Check label="Enable email gateway" name="email_gateway_enabled" checked={email.data.email_gateway_enabled} onChange={(e) => email.setData('email_gateway_enabled', e.target.checked)} />
                    <Select label="Transport" name="email_gateway_transport" options={{ smtp: 'SMTP', mail: 'PHP mail()' }} value={email.data.email_gateway_transport} onChange={(e) => email.setData('email_gateway_transport', e.target.value)} />
                    {text('SMTP host', 'email_gateway_host')}
                    {text('SMTP port', 'email_gateway_port', { type: 'number', min: 1, max: 65535 })}
                    <Select label="Encryption" name="email_gateway_encryption" options={{ tls: 'STARTTLS', ssl: 'SSL/TLS', none: 'None' }} value={email.data.email_gateway_encryption} onChange={(e) => email.setData('email_gateway_encryption', e.target.value)} />
                    {text('Timeout seconds', 'email_gateway_timeout', { type: 'number', min: 3, max: 60 })}
                    {text('SMTP username', 'email_gateway_username')}
                    {text('SMTP password', 'email_gateway_password', { type: 'password', autoComplete: 'new-password', hint: 'Leave blank to keep the current password.' })}
                    {text('From email', 'email_gateway_from_email', { type: 'email' })}
                    {text('From name', 'email_gateway_from_name')}
                    {text('Reply-to email', 'email_gateway_reply_to', { type: 'email' })}
                    <div className="span2 form-actions"><button className="primary" disabled={email.processing}>Save email gateway</button></div>
                </form>
            </Sheet>
        );
    }
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
            <div className="settings-section-intro"><h3>{anyReady ? 'Your delivery channels' : '1. Connect a gateway'}</h3><p>Enable Email, SMS, or both. Then choose the channels for each template.</p></div>
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
            <div className="settings-section-intro"><h3>Three messages. One clear workflow.</h3><p>Welcome and Service extended send automatically when enabled. Custom is sent only after your review.</p></div>
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
            <p className="notice-footnote">Customer Email and SMS alert preferences are always respected.</p>
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
                        { header: 'Queued (UTC)', cell: (r) => r.queued },
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

function NotificationsTab({ n }: { n: Notifications }) {
    const anyReady = n.ready.email || n.ready.sms;
    const views: [string, string][] = [['templates', 'Templates'], ['gateways', 'Gateways'], ['history', 'History']];
    return (
        <div className="settings-notifications notice-workspace">
            <div className="notice-heading">
                <div><h2>Customer notifications</h2><p>Useful messages, at the right moment.</p></div>
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
        </div>
    );
}

export default function Settings({ tab, overview, users, usersSummary, roleDescriptions, branding, notifications }: {
    tab: 'overview' | 'users' | 'notifications' | 'branding';
    overview: Overview;
    users: Operator[] | null;
    usersSummary: { enabled: number; total: number };
    roleDescriptions: { role: string; description: string }[];
    branding: Branding;
    notifications: Notifications | null;
}) {
    return (
        <AppLayout title="Settings">
            <div className="settings-console">
                <SettingsLinkNav active={tab} />
                <div className="settings-menu-body">
                    {tab === 'overview' && <OverviewTab overview={overview} usersSummary={usersSummary} branding={branding} />}
                    {tab === 'users' && <UsersTab users={users} usersSummary={usersSummary} roleDescriptions={roleDescriptions} />}
                    {tab === 'notifications' && notifications && <NotificationsTab n={notifications} />}
                    {tab === 'branding' && <BrandingTab branding={branding} />}
                </div>
            </div>
        </AppLayout>
    );
}
