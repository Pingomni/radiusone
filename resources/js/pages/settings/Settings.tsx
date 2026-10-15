import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { DeleteButton, PostButton, useConfirm } from '@/components/confirm';
import Icon from '@/components/Icon';
import { RoleGrid, Sheet, SettingsLinkNav, SettingsRows } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Check, Empty, Field, Select, Tag, TextArea, type TagData } from '@/components/ui';
import AppLayout, { BrandLogo } from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { bind } from '@/lib/form';
import { bytes } from '@/lib/format';

type Overview = { engine: TagData; rows: [string, string][] };
type Operator = { id: number; name: string; email: string; username: string; role: string; enabled: boolean; twoFactor: TagData; lastLogin: string; self: boolean };
type Branding = { custom: boolean; remote: boolean; url: string; hasSetting: boolean };
type Template = { subject: string; email_body: string; sms_body: string; email_enabled: string; sms_enabled: string };
type Bulk = { channel: 'email' | 'sms'; q: string; rows: { id: number; username: string; recipient: string; eligibility: string }[]; more: boolean; selected: number[]; audience: string; subject: string; body: string };
type PreviewItem = { username: string; recipient: string; reason: string; subject: string; body: string };
type Preview = { token: string; channel: string; eligible: number; items: PreviewItem[]; sample: PreviewItem };
type HistoryRow = { id: number; queued: string; username: string; template: string; channel: string; recipient: string; status: TagData; reason: string };
type SmsGateway = {
    enabled: string; provider: string; country_code: string; method: string; url: string; body: string; success: string;
    twilio_sid: string; twilio_from: string; twilio_token_saved: boolean; infobip_base_url: string; infobip_from: string; infobip_api_key_saved: boolean;
};
type EmailGateway = {
    enabled: string; provider: string; transport: string; host: string; port: string; encryption: string; username: string; from_email: string; from_name: string;
    reply_to: string; timeout: string; password_saved: boolean; ms_tenant_id: string; ms_client_id: string; ms_client_secret_saved: boolean;
    mailgun_domain: string; mailgun_region: string; mailgun_api_key_saved: boolean;
};
type Notifications = {
    view: 'templates' | 'gateways' | 'bulk' | 'preview' | 'history';
    ready: { email: boolean; sms: boolean };
    gateway?: 'email' | 'sms' | null;
    providers?: Record<string, string>;
    sms?: SmsGateway;
    email?: EmailGateway;
    labels?: Record<string, string>;
    tokens?: string[];
    templates?: Record<string, Template>;
    template?: string | null;
    bulk?: Bulk | null;
    preview?: Preview | null;
    history?: { before: number; older: number | null; rows: HistoryRow[] };
};

type BackupDestination = {
    id: string; type: 'gdrive' | 's3' | 'sftp'; name: string; enabled: boolean; keep: number; connected: boolean;
    client_id?: string; folder?: string; client_secret_saved?: boolean; refresh_token_saved?: boolean; connected_at?: string;
    region?: string; bucket?: string; prefix?: string; access_key?: string; secret_key_saved?: boolean;
    host?: string; port?: string; user?: string; path?: string; public_key?: string; fingerprint?: string;
};
type BackupRun = {
    at: string; ok: boolean; error?: string; database?: { file: string; size: number }; documents?: { file: string; size: number };
    destinations: Record<string, { name: string; type: string; ok: boolean; message: string; removed: number }>; local_removed?: number;
};
type Backups = {
    passphraseSet: boolean; localDays: number; localDaysOptions: Record<string, string>; destinations: BackupDestination[];
    types: Record<string, string>; keepOptions: Record<string, string>; status: BackupRun | null;
    local: { name: string; size: number; at: string }[]; localCount: number; googleRedirect: string;
};
type RetentionRun = { sessions: number; logins: number; finished: boolean; at: string };
type Retention = {
    periods: { sessions: number; logins: number };
    stats: { sessions: { rows: number; oldest: string }; logins: { rows: number; oldest: string }; lastRun: RetentionRun | null };
    options: { sessions: Record<string, string>; logins: Record<string, string> };
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
                <Destination title="Backups" description="Encrypted copies to Google Drive, Amazon S3 or your own server." href="/settings?tab=backups" icon="server" status={<Badge>Remote copies</Badge>} />
                <Destination title="Data retention" description="Choose how long session and login records are kept." href="/settings?tab=retention" icon="logs" status={<Badge>Session and login history</Badge>} />
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

function BackupStatus({ b }: { b: Backups }) {
    const run = b.status;
    return (
        <Sheet
            title="Last backup"
            description="Every night at 02:15 UTC the database and customer documents are backed up, encrypted and uploaded to each enabled destination."
            icon="server"
            meta={run ? <Badge tone={run.ok ? 'success' : 'danger'}>{run.ok ? 'completed' : 'needs attention'}</Badge> : <Badge>not run yet</Badge>}
        >
            {run ? (
                <>
                    <SettingsRows rows={[
                        ['Finished', `${run.at} UTC`],
                        ['Database', run.database ? `${run.database.file} · ${bytes(run.database.size)}` : '—'],
                        ['Documents', run.documents ? `${run.documents.file} · ${bytes(run.documents.size)}` : '—'],
                        ['Local copies', `${b.localCount} files${run.local_removed ? ` · ${run.local_removed} old copies removed` : ''}`],
                    ]} />
                    {run.error && <p className="field-error">{run.error}</p>}
                    {Object.entries(run.destinations).map(([id, d]) => (
                        <p key={id} className={d.ok ? 'hint' : 'field-error'}>{d.name}: {d.message}{d.removed ? ` ${d.removed} old copies removed.` : ''}</p>
                    ))}
                    {Object.keys(run.destinations).length === 0 && !run.error && <p className="hint">No remote destination was enabled, so this backup exists only on this server.</p>}
                </>
            ) : (
                <p className="hint">No backup has run with remote copies yet. To run one now on the server: sudo FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env php /opt/freeradius-web-laravel/artisan frweb:backup</p>
            )}
        </Sheet>
    );
}

function BackupPassphrase({ set }: { set: boolean }) {
    const form = useForm({ passphrase: '', passphrase_confirmation: '' });
    return (
        <Sheet
            title="Encryption"
            description="Backups are encrypted on this server before they are uploaded. Only someone with the passphrase can open them."
            icon="lock"
            meta={<Badge tone={set ? 'success' : 'warning'}>{set ? 'passphrase set' : 'no passphrase'}</Badge>}
        >
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/settings/backups/passphrase', { preserveScroll: true, onFinish: () => form.reset() }); }}>
                <p className="span2 hint">
                    Write the passphrase down and keep it somewhere safe outside this server. If this server is lost, you need it to restore a remote backup
                    (artisan frweb:backup-decrypt). Changing it only affects new backups; older copies still need the old passphrase.
                </p>
                <Field label={set ? 'New passphrase' : 'Passphrase'} type="password" autoComplete="new-password" minLength={12} required error={form.errors.passphrase} hint="At least 12 characters; a few random words work well." value={form.data.passphrase} onChange={(e) => form.setData('passphrase', e.target.value)} />
                <Field label="Repeat passphrase" type="password" autoComplete="new-password" required value={form.data.passphrase_confirmation} onChange={(e) => form.setData('passphrase_confirmation', e.target.value)} />
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>{set ? 'Change passphrase' : 'Save passphrase'}</button></div>
            </form>
        </Sheet>
    );
}

function DestinationForm({ d, b, onDone }: { d: BackupDestination | null; b: Backups; onDone: () => void }) {
    const form = useForm({
        id: d?.id ?? '', type: d?.type ?? 'gdrive', name: d?.name ?? '', enabled: d?.enabled ?? true, keep: String(d?.keep ?? 14),
        client_id: d?.client_id ?? '', client_secret: '', folder: d?.folder ?? 'FreeRADIUS Web backups',
        region: d?.region ?? 'eu-central-1', bucket: d?.bucket ?? '', prefix: d?.prefix ?? 'freeradius-web', access_key: d?.access_key ?? '', secret_key: '',
        host: d?.host ?? '', port: d?.port ?? '22', user: d?.user ?? '', path: d?.path ?? 'backups/freeradius-web', rescan_host_key: false,
    });
    const type = form.data.type;
    return (
        <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/settings/backups/destinations', { preserveScroll: true, onSuccess: onDone, onFinish: () => form.setData((v) => ({ ...v, client_secret: '', secret_key: '' })) }); }}>
            {!d && <Select label="Type" options={b.types} {...bind(form, 'type')} />}
            <Field label="Name" required placeholder={type === 'gdrive' ? 'Office Google Drive' : type === 's3' ? 'AWS Frankfurt' : 'Backup server'} {...bind(form, 'name')} />
            {type === 'gdrive' && (
                <>
                    <p className="span2 hint">
                        In Google Cloud Console: enable the Google Drive API, create an OAuth client of type “Web application”, and add this authorised redirect URI: <code>{b.googleRedirect}</code>.
                        After saving, use Connect to sign in. The app only sees the folder it creates.
                    </p>
                    <Field label="OAuth client ID" placeholder="…apps.googleusercontent.com" {...bind(form, 'client_id')} />
                    <SecretField label="OAuth client secret" name="client_secret" saved={Boolean(d?.client_secret_saved)} value={form.data.client_secret} error={form.errors.client_secret} onChange={(v) => form.setData('client_secret', v)} />
                    <Field label="Drive folder name" hint="Created in the connected Drive when you connect." {...bind(form, 'folder')} />
                </>
            )}
            {type === 's3' && (
                <>
                    <p className="span2 hint">Use an IAM user that can only s3:PutObject, s3:ListBucket and s3:DeleteObject on this bucket and folder.</p>
                    <Field label="Region" placeholder="eu-central-1" {...bind(form, 'region')} />
                    <Field label="Bucket" {...bind(form, 'bucket')} />
                    <Field label="Folder in the bucket" hint="Optional." {...bind(form, 'prefix')} />
                    <Field label="Access key ID" {...bind(form, 'access_key')} />
                    <SecretField label="Secret access key" name="secret_key" saved={Boolean(d?.secret_key_saved)} value={form.data.secret_key} error={form.errors.secret_key} onChange={(v) => form.setData('secret_key', v)} />
                </>
            )}
            {type === 'sftp' && (
                <>
                    <p className="span2 hint">A key pair is generated when you save; add its public key to the server user’s ~/.ssh/authorized_keys. The server’s host key is recorded and checked on every upload.</p>
                    <Field label="Host" {...bind(form, 'host')} />
                    <Field label="Port" type="number" min={1} max={65535} {...bind(form, 'port')} />
                    <Field label="User" {...bind(form, 'user')} />
                    <Field label="Folder" hint="Relative to the user’s home, or an absolute path." {...bind(form, 'path')} />
                    {d && <Check label="Re-check the server’s host key (only after you changed it on purpose)" checked={form.data.rescan_host_key} onChange={(e) => form.setData('rescan_host_key', e.target.checked)} />}
                </>
            )}
            <Select label="Copies to keep" options={b.keepOptions} {...bind(form, 'keep')} />
            <Check label="Enabled" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} />
            <div className="span2 form-actions">
                <button className="primary" disabled={form.processing}>{d ? 'Save destination' : 'Add destination'}</button>
                <button type="button" onClick={onDone}>Cancel</button>
            </div>
        </form>
    );
}

function DestinationRow({ d, b, open, onToggle }: { d: BackupDestination; b: Backups; open: boolean; onToggle: () => void }) {
    const detail = d.type === 'gdrive' ? `Folder “${d.folder}”` : d.type === 's3' ? `${d.bucket}${d.prefix ? '/' + d.prefix : ''} · ${d.region}` : `${d.user}@${d.host}:${d.port} · ${d.path}`;
    return (
        <article className="backup-destination">
            <div className="backup-destination-head">
                <div>
                    <h3>{d.name}</h3>
                    <p className="hint">{b.types[d.type]} · {detail} · keeps {d.keep} copies</p>
                </div>
                <span className="toolbar-actions">
                    <Badge tone={d.enabled && d.connected ? 'success' : 'warning'}>{!d.connected ? 'not connected' : d.enabled ? 'enabled' : 'disabled'}</Badge>
                    {d.type === 'gdrive' && <a className="button" href={`/settings/backups/destinations/${d.id}/google`}>{d.connected ? 'Reconnect' : 'Connect Google Drive'}</a>}
                    {d.connected && <PostButton action={`/settings/backups/destinations/${d.id}/test`} label="Test" className="button" />}
                    <button type="button" onClick={onToggle}>{open ? 'Close' : 'Edit'}</button>
                    <DeleteButton action={`/settings/backups/destinations/${d.id}/delete`} label="Remove" message={`Stop sending backups to ${d.name}? Backups already stored there are kept.`} />
                </span>
            </div>
            {d.type === 'sftp' && d.public_key && (
                <div className="backup-sftp-key">
                    <TextArea label="Public key for ~/.ssh/authorized_keys" readOnly rows={2} value={d.public_key} />
                    <button type="button" onClick={() => void navigator.clipboard?.writeText(d.public_key ?? '')}>Copy public key</button>
                    <p className="hint">Server host key: {d.fingerprint}. Compare it with the server (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub).</p>
                </div>
            )}
            {open && <DestinationForm d={d} b={b} onDone={onToggle} />}
        </article>
    );
}

function BackupDestinationsSheet({ b }: { b: Backups }) {
    const initial = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('destination') : null;
    const [open, setOpen] = useState<string | null>(initial);
    return (
        <Sheet title="Remote destinations" description="Where encrypted copies are sent. Use more than one for extra safety." icon="server" meta={<Badge>{b.destinations.length} configured</Badge>}>
            {!b.passphraseSet && b.destinations.length > 0 && <p className="field-error">Set a passphrase first: nothing is uploaded unencrypted.</p>}
            {b.destinations.map((d) => <DestinationRow key={d.id} d={d} b={b} open={open === d.id} onToggle={() => setOpen(open === d.id ? null : d.id)} />)}
            {b.destinations.length === 0 && open !== 'new' && <Empty>No remote destination yet. Backups exist only on this server.</Empty>}
            {open === 'new' ? <DestinationForm d={null} b={b} onDone={() => setOpen(null)} /> : <div className="form-actions"><button className="primary" type="button" onClick={() => setOpen('new')}>Add destination</button></div>}
        </Sheet>
    );
}

function BackupLocal({ b }: { b: Backups }) {
    const form = useForm({ days: String(b.localDays) });
    return (
        <Sheet title="Local copies" description="Backups also stay on this server; older ones are removed (the newest three of each kind are always kept)." icon="logs">
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/settings/backups/local', { preserveScroll: true }); }}>
                <Select label="Keep local copies for" options={b.localDaysOptions} {...bind(form, 'days')} />
                <div className="form-actions"><button disabled={form.processing}>Save</button></div>
            </form>
            <DataTable rows={b.local} rowKey={(f) => f.name} columns={[
                { header: 'File', cell: (f) => f.name },
                { header: 'Size', cell: (f) => bytes(f.size) },
                { header: 'Created (UTC)', cell: (f) => f.at },
            ]} />
        </Sheet>
    );
}

function BackupsTab({ b }: { b: Backups }) {
    return (
        <>
            <BackupStatus b={b} />
            <BackupPassphrase set={b.passphraseSet} />
            <BackupDestinationsSheet b={b} />
            <BackupLocal b={b} />
        </>
    );
}

/** Days of history a period keeps; "Keep forever" (0) keeps everything. */
const keeps = (days: number) => (days === 0 ? Infinity : days);

function RetentionTab({ r }: { r: Retention }) {
    const form = useForm({ sessions: String(r.periods.sessions), logins: String(r.periods.logins) });
    const ask = useConfirm();
    const label = (kind: 'sessions' | 'logins', days: string) => r.options[kind][days] ?? days;
    const stat = (s: { rows: number; oldest: string }) => `About ${s.rows.toLocaleString()} records${s.oldest ? `, oldest from ${s.oldest.slice(0, 10)}` : ''}.`;
    const last = r.stats.lastRun;

    return (
        <Sheet
            title="Data retention"
            description="How long RADIUS connection history is kept. Records older than the chosen period are deleted in a nightly cleanup."
            icon="logs"
            meta={<Badge tone={r.periods.sessions === 0 && r.periods.logins === 0 ? '' : 'warning'}>{r.periods.sessions === 0 && r.periods.logins === 0 ? 'Keeping everything' : 'Cleanup active'}</Badge>}
        >
            <form
                className="form-grid"
                onSubmit={async (event) => {
                    event.preventDefault();
                    const shorter = (['sessions', 'logins'] as const).filter((kind) => keeps(Number(form.data[kind])) < keeps(r.periods[kind]));
                    if (shorter.length) {
                        const what = shorter.map((kind) => `${kind === 'sessions' ? 'session' : 'login'} records older than ${label(kind, form.data[kind]).toLowerCase()}`).join(' and ');
                        const ok = await ask({
                            title: 'Delete older records?',
                            message: `The nightly cleanup will permanently delete ${what}. They can only be recovered from a database backup.`,
                            confirmLabel: 'Save and delete',
                        });
                        if (!ok) return;
                    }
                    form.post('/settings/retention', { preserveScroll: true });
                }}
            >
                <Select
                    label="Session records (radacct)"
                    hint={`Who was connected, when, from which IP address and how much traffic. ${stat(r.stats.sessions)} Live sessions are never deleted.`}
                    options={r.options.sessions}
                    name="sessions"
                    value={form.data.sessions}
                    error={form.errors.sessions}
                    onChange={(e) => form.setData('sessions', e.target.value)}
                />
                <Select
                    label="Login records (radpostauth)"
                    hint={`Every accepted and rejected sign-in attempt. ${stat(r.stats.logins)}`}
                    options={r.options.logins}
                    name="logins"
                    value={form.data.logins}
                    error={form.errors.logins}
                    onChange={(e) => form.setData('logins', e.target.value)}
                />
                <p className="span2 hint">
                    Check how long you are legally required to keep connection records (who had which IP address and when) before choosing a period.
                    Traffic totals on client profiles and reports only cover the records that are kept.
                </p>
                <p className="span2 hint">
                    {last
                        ? `Last cleanup ${last.at} UTC: ${last.sessions.toLocaleString()} session and ${last.logins.toLocaleString()} login records deleted${last.finished ? '.' : '; more remain and are deleted the next night.'}`
                        : 'The cleanup runs every night at 03:45 UTC; it has not deleted anything yet.'}
                </p>
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Save retention</button></div>
            </form>
        </Sheet>
    );
}

/** Credential input: blank keeps the saved value, which is never sent to the browser. */
function SecretField({ label, name, saved, value, onChange, error, hint }: { label: string; name: string; saved: boolean; value: string; onChange: (v: string) => void; error?: string; hint?: string }) {
    return (
        <Field
            label={label}
            name={name}
            type="password"
            autoComplete="new-password"
            value={value}
            error={error}
            onChange={(e) => onChange(e.target.value)}
            placeholder={saved ? 'Saved — leave blank to keep' : ''}
            hint={[hint, saved ? 'A value is saved. Type a new one only to replace it.' : ''].filter(Boolean).join(' ') || undefined}
        />
    );
}

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
                <Check label="Enable SMS gateway" name="sms_gateway_enabled" checked={form.data.sms_gateway_enabled} onChange={(e) => form.setData('sms_gateway_enabled', e.target.checked)} />
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
        email_gateway_from_name: g.from_name,
        email_gateway_reply_to: g.reply_to,
        email_gateway_ms_tenant_id: g.ms_tenant_id,
        email_gateway_ms_client_id: g.ms_client_id,
        email_gateway_ms_client_secret: '',
        email_gateway_mailgun_domain: g.mailgun_domain,
        email_gateway_mailgun_region: g.mailgun_region,
        email_gateway_mailgun_api_key: '',
    });
    const provider = form.data.email_gateway_provider;
    const clearSecrets = () => form.setData((d) => ({ ...d, email_gateway_password: '', email_gateway_ms_client_secret: '', email_gateway_mailgun_api_key: '' }));
    const secret = (label: string, key: 'email_gateway_password' | 'email_gateway_ms_client_secret' | 'email_gateway_mailgun_api_key', saved: boolean, hint?: string) => (
        <SecretField label={label} name={key} saved={saved} value={form.data[key]} error={form.errors[key]} onChange={(v) => form.setData(key, v)} hint={hint} />
    );
    return (
        <Sheet title="Email gateway" description="Choose your email provider and the sender customers will recognize." icon="mail" meta={g.enabled === '1' ? <Badge tone="success">gateway enabled</Badge> : <Badge tone="warning">gateway disabled</Badge>}>
            <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/settings/notifications/email-gateway', { preserveScroll: true, onFinish: clearSecrets }); }}>
                <Check label="Enable email gateway" name="email_gateway_enabled" checked={form.data.email_gateway_enabled} onChange={(e) => form.setData('email_gateway_enabled', e.target.checked)} />
                <Select label="Provider" options={providers} {...bind(form, 'email_gateway_provider')} />
                {provider === 'google' && (
                    <>
                        <Field label="Google account" type="email" hint="The Gmail or Google Workspace address that signs in." {...bind(form, 'email_gateway_username')} />
                        {secret('App password', 'email_gateway_password', g.password_saved, 'Google Account → Security → 2-Step Verification → App passwords. Your normal password does not work.')}
                    </>
                )}
                {provider === 'microsoft' && (
                    <>
                        <p className="span2 hint">In Microsoft Entra (Azure), register an app, add the Microsoft Graph application permission Mail.Send, grant admin consent, and create a client secret. The From email below must be a mailbox in your organisation.</p>
                        <Field label="Tenant ID or domain" placeholder="contoso.onmicrosoft.com" {...bind(form, 'email_gateway_ms_tenant_id')} />
                        <Field label="Application (client) ID" {...bind(form, 'email_gateway_ms_client_id')} />
                        {secret('Client secret', 'email_gateway_ms_client_secret', g.ms_client_secret_saved, 'The secret value, not its ID. Secrets expire; set a reminder to renew it.')}
                    </>
                )}
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
                <Field label="From email" type="email" hint={provider === 'mailgun' ? 'An address on your Mailgun sending domain.' : undefined} {...bind(form, 'email_gateway_from_email')} />
                <Field label="From name" {...bind(form, 'email_gateway_from_name')} />
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

export default function Settings({ tab, overview, users, usersSummary, roleDescriptions, branding, notifications, retention, backups }: {
    tab: 'overview' | 'users' | 'notifications' | 'branding' | 'retention' | 'backups';
    overview: Overview;
    users: Operator[] | null;
    usersSummary: { enabled: number; total: number };
    roleDescriptions: { role: string; description: string }[];
    branding: Branding;
    notifications: Notifications | null;
    retention: Retention | null;
    backups: Backups | null;
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
                    {tab === 'retention' && retention && <RetentionTab r={retention} />}
                    {tab === 'backups' && backups && <BackupsTab b={backups} />}
                </div>
            </div>
        </AppLayout>
    );
}
