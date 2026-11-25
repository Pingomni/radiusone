import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { InvoiceTable, ReceiptActions, StatCards, type InvoiceRow, type Stat } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { LivePanel } from '@/components/LivePanel';
import { DataTable } from '@/components/table';
import ClientOnu, { type ClientOnuData } from '@/components/ClientOnu';
import { Tabs } from '@/components/tabs';
import { TrafficCard, UsageCard, type UsageHistory } from '@/components/usage';
import type { ChartPayload } from '@/components/LineChart';
import { Check, ConnectionState, Empty, ErrorSummary, Field, Panel, Select, Tag, TextArea, type TagData, State } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { bind, bindCheck } from '@/lib/form';
import { randomPassword } from '@/lib/format';

type Values = Record<string, string | boolean | File | null> & {
    old_username: string; auth_method: string; username: string; password: string; account_status: string; framed_ip_address: string;
    email_alert: boolean; sms_alert: boolean; mac_lock: boolean;
};
type StaticIps = { pools: { key: string; label: string; ips: string[] }[]; currentPool: string; currentIp: string };
type OverviewItem = { label: string; value: string; tone: string };
type Overview = {
    items: OverviewItem[];
    revealPassword: boolean;
    disconnect: null | { ready: true; nasId: number; radacctId: number; message: string } | { ready: false; reason: string };
};
type Header = { name: string; initials: string; status: TagData; auth: TagData; contacts: { icon: string; text: string }[]; newInvoice: boolean; stats: Stat[] };
type Session = { start: string; stop: string; state: string; time: string; ip: string; nas: string; down: string; up: string; cause: string };
type Payment = { id: number; date: string; invoiceId: number; invoice: string; amount: string; method: string; reference: string };
type Client = {
    username: string;
    header: Header;
    overview: Overview;
    usage: UsageHistory;
    traffic: { range: string; data: ChartPayload };
    sessions: Session[];
    billing: { newInvoice: boolean; stats: Stat[]; overdue: string | null; invoices: InvoiceRow[]; payments: Payment[] };
    documents: { id: number; type: string; name: string; mime: string; uploaded: string }[];
    logs: { actor: string; action: string; entity: string; time: string }[];
    revealed: string | null;
    onu: ClientOnuData | null;
};

function Section({ title, children }: { title: string; description?: string; icon?: string; children: ReactNode }) {
    return (
        <section className="client-section">
            <header className="client-section-head">
                <h3>{title}</h3>
            </header>
            <div className="form-grid">{children}</div>
        </section>
    );
}

/** A DHCP client's new device: its MAC address is its username, so the new MAC replaces it; the client keeps the rest. */
function ChangeDevice({ username }: { username: string }) {
    const [open, setOpen] = useState(false);
    const [mac, setMac] = useState('');
    const [busy, setBusy] = useState(false);
    const error = (usePage().props.errors as Record<string, string> | undefined)?.device_mac;
    const change = () => router.post(`/clients/${encodeURIComponent(username)}/device`, { mac_address: mac }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    if (!open && !error) return <button type="button" className="button" onClick={() => setOpen(true)}><Icon name="refresh" />Change device</button>;
    return (
        <div className="span2">
            <Field label="New device's MAC address" name="device_mac" value={mac} onChange={(e) => setMac(e.target.value)} placeholder="AA:BB:CC:DD:EE:FF" error={error}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (mac.trim() !== '') change(); } }}
                hint="The new MAC becomes the username. The plan, billing and history stay with this client." />
            <div className="form-actions">
                <button type="button" className="button primary" disabled={busy || mac.trim() === ''}
                    onClick={change}>
                    {busy ? 'Changing…' : 'Change device'}
                </button>
                <button type="button" className="button" onClick={() => { setOpen(false); setMac(''); }}>Cancel</button>
            </div>
        </div>
    );
}

function ProfileHeader({ username, header }: { username: string; header: Header }) {
    return (
        <section className="client-hero" aria-label="Client summary">
            <div className="client-identity">
                <div className="client-identity-text">
                    <h1>{header.name}</h1>
                    <div className="client-identity-meta"><span className="mono">{username}</span><Tag tag={header.status} />{header.auth.text !== 'PPPoE' && <Tag tag={header.auth} />}</div>
                    {header.contacts.length > 0 && (
                        <div className="client-contacts">{header.contacts.map((c) => <span key={c.icon}><Icon name={c.icon} />{c.text}</span>)}</div>
                    )}
                </div>
                <div className="client-hero-actions">
                    <Link className="button" href={`/clients/${encodeURIComponent(username)}/diagnostics`}><Icon name="signal" />Diagnostics</Link>
                    {header.newInvoice && <Link className="button" href={`/billing/invoices/create?username=${encodeURIComponent(username)}`}><Icon name="billing" />New invoice</Link>}
                </div>
            </div>
            <StatCards stats={header.stats} />
        </section>
    );
}

function OverviewPanel({ username, initial }: { username: string; initial: Overview }) {
    const enc = encodeURIComponent(username);
    return (
        <LivePanel
            title="Account"
            url={`/clients/${enc}/overview`}
            interval={15}
            initial={initial}
            className="overview"
            render={(o) => (
                <>
                    {o.items.map((i) => (
                        <div className="kv" key={i.label}><span>{i.label}</span>{i.tone ? <State tone={i.tone}>{i.value}</State> : <strong className={i.value.length <= 22 ? 'nowrap' : undefined}>{i.value}</strong>}</div>
                    ))}
                    <Link className="button wide" href={`/clients/${enc}/diagnostics`}>Connection diagnostics</Link>
                    {o.revealPassword && (
                        <>
                            <PostButton action={`/clients/${enc}/reveal-password`} className="button wide" label="Reveal saved password" />
                            <small className="overview-note">Each reveal is logged.</small>
                        </>
                    )}
                    {o.disconnect && (o.disconnect.ready ? (
                        <PostButton
                            action={`/clients/${enc}/disconnect`}
                            data={{ nas_id: o.disconnect.nasId, radacctid: o.disconnect.radacctId }}
                            className="danger wide"
                            label="Disconnect active session"
                            confirm={{ title: `Disconnect ${username}?`, message: o.disconnect.message, confirmLabel: 'Disconnect' }}
                        />
                    ) : (
                        <>
                            <button type="button" className="wide" disabled>Disconnect unavailable</button>
                            <small className="overview-note">{o.disconnect.reason}</small>
                        </>
                    ))}
                </>
            )}
        />
    );
}

/** Seven days of session counters by last update date (legacy clientTrafficGraph). */
function StaticIpFields({ staticIps, value, disabled, error, onChange }: { staticIps: StaticIps; value: string; disabled: boolean; error?: string; onChange: (ip: string) => void }) {
    const poolOf = (ip: string) => staticIps.pools.find((p) => p.ips.includes(ip))?.key ?? '';
    const [pool, setPool] = useState(value ? poolOf(value) || staticIps.currentPool : '');
    const chosen = staticIps.pools.find((p) => p.key === pool);
    return (
        <>
            <Select
                label="Static IP pool"
                name="static_ip_pool"
                disabled={disabled}
                value={pool}
                placeholder="Use dynamic IP / no static IP"
                options={staticIps.pools.map((p) => ({ value: p.key, label: p.label }))}
                onChange={(e) => { setPool(e.target.value); onChange(''); }}
            />
            {pool !== '' && chosen && (
                <Select
                    label="Static IP address"
                    name="framed_ip_address"
                    disabled={disabled}
                    error={error}
                    value={value}
                    placeholder="Use dynamic IP / no static IP"
                    options={chosen.ips.map((ip) => ({ value: ip, label: ip === staticIps.currentIp ? `${ip} (current)` : ip }))}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
        </>
    );
}

export default function ClientForm({ values, draftRestored, draftTab, canEdit, canUpload, plans, staticIps, binding, payPage, client }: {
    values: Values;
    draftRestored: boolean;
    draftTab: string | null;
    canEdit: boolean;
    canUpload: boolean;
    plans: Record<string, string>;
    staticIps: StaticIps;
    binding: { options: Record<string, string> } | null;
    payPage: boolean;
    client: Client | null;
}) {
    const can = useCan();
    const form = useForm<Values>({ ...values, id_front: null, id_back: null });
    const [tab, setTab] = useState(draftTab ?? (typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null) ?? (client ? 'overview' : 'account'));
    // Overview, Accounting, Billing and Logs are read-only views: the form's Save bar does not apply there.
    const formTab = !['overview', 'accounting', 'billing', 'logs', 'onu'].includes(tab);
    const [generated, setGenerated] = useState(false);
    const disabled = !canEdit;
    const dhcp = form.data.auth_method === 'dhcp';
    const field = (label: string, key: string, props: Record<string, unknown> = {}) => <Field label={label} disabled={disabled} {...bind(form, key)} {...props} />;

    const submit = async () => {
        // Unchecked boxes and empty file inputs are left out, as a browser form post would.
        form.transform((data) => Object.fromEntries(Object.entries({ ...data, _active_tab: tab }).filter(([, v]) => v !== false && v !== null)));
        form.post('/clients/save', { forceFormData: true, preserveScroll: true, onSuccess: () => setGenerated(false), onFinish: () => form.setData('password', '') });
    };

    const accountTab = (
        <>
            <Section title="Credentials" description="How the customer authenticates against RADIUS." icon="lock">
                <Select label="Authentication" disabled={disabled || Boolean(client)} options={{ pppoe: 'PPPoE username/password', dhcp: 'DHCP MAC address' }} {...bind(form, 'auth_method')} />
                {field('Username', 'username', { hint: client ? 'Set when the client was created; it cannot be changed.' : 'Set once: it cannot be changed later. For DHCP this is saved as the MAC address.', readOnly: dhcp || Boolean(client), value: dhcp ? String(form.data.mac_address ?? '') : form.data.username })}
                {!dhcp && (
                    <Field
                        label="PPPoE password"
                        type={generated ? 'text' : 'password'}
                        required={!client}
                        disabled={disabled}
                        autoComplete="new-password"
                        hint={client ? 'Leave blank to keep the current password.' : undefined}
                        {...bind(form, 'password')}
                    />
                )}
                {!disabled && !dhcp && (
                    <div className="span2 inline-actions">
                        <button type="button" className="button" onClick={() => { form.setData('password', randomPassword(14)); setGenerated(true); }}>Generate password</button>
                        {generated && <button type="button" className="button" onClick={() => void navigator.clipboard?.writeText(form.data.password)}>Copy password</button>}
                        {generated && <span className="hint" role="status">Saved when you save the client.</span>}
                    </div>
                )}
            </Section>
            <Section title="Service and status" description="Plan, entitlement period and addressing." icon="services">
                <Select label="Account status" disabled={disabled} options={{ enabled: 'enabled', disabled: 'disabled', suspended: 'suspended' }} {...bind(form, 'account_status')} />
                {field('Expiry date', 'expiry_date', { type: 'date' })}
                <Select label="Service plan" required disabled={disabled} options={plans} placeholder="Choose a plan" {...bind(form, 'service_group')} />
                <StaticIpFields staticIps={staticIps} value={form.data.framed_ip_address} disabled={disabled} error={form.errors.framed_ip_address} onChange={(ip) => form.setData('framed_ip_address', ip)} />
                <div className="alert-options span2">
                    <span className="alert-options-label">Customer alerts</span>
                    <Check label="Email alert" disabled={disabled} {...bindCheck(form, 'email_alert')} />
                    <Check label="SMS alert" disabled={disabled} {...bindCheck(form, 'sms_alert')} />
                </div>
                {payPage && (
                    <div className="alert-options span2">
                        <span className="alert-options-label">Online payment</span>
                        <Check label="May pay on the pay page" disabled={disabled} {...bindCheck(form, 'online_pay')} />
                    </div>
                )}
            </Section>
            <Section title="Access restrictions" description="Limit where and from which device the account can connect." icon="shield">
                {binding ? (
                    <>
                        {field('DHCP client identifier', 'dhcp_client_id')}
                        {field('Trusted NAS access circuit', 'circuit_id', { hint: 'Only on an isolated access port or VLAN.' })}
                    </>
                ) : <p className="span2">Access profiles require migration 018 before provisioning.</p>}
                {field('CPE MAC address', 'mac_address', { required: dhcp, readOnly: dhcp && Boolean(client), hint: dhcp && client ? 'The MAC address is this client\'s username. A new router? Use Change device.' : undefined })}
                {dhcp && client && canEdit && <ChangeDevice username={values.username} />}
                {!dhcp && <Check label="Allow this MAC only" disabled={disabled} {...bindCheck(form, 'mac_lock')} />}
                <Select label="NAS restriction" disabled={disabled} options={{ all: 'All NAS', nas_ip: 'NAS-IP-Address', nas_identifier: 'NAS-Identifier' }} {...bind(form, 'nas_restriction_type')} />
                {field('NAS restriction value', 'nas_restriction_value')}
            </Section>
        </>
    );

    const personalTab = (
        <>
            <Section title="Identity" description="Who the account belongs to." icon="user">
                {field('First name', 'first_name')}
                {field('Last name', 'last_name')}
                {field('Company name', 'company_name')}
                {field('VAT/Tax ID', 'vat_tax_id')}
                {field('Language', 'language')}
            </Section>
            <Section title="Contact" description="Used for alerts, invoices and support calls." icon="mail">
                {field('Phone', 'phone')}
                {field('Cell phone', 'cell_phone')}
                {field('Email', 'email', { type: 'email' })}
            </Section>
            <Section title="Installation address" description="Service location of the customer premises equipment." icon="server">
                {field('Address', 'address')}
                {field('City', 'city')}
                {field('State', 'state')}
                {field('ZIP', 'zip')}
                {field('Country', 'country')}
                {field('GPS/geolocation', 'gps')}
            </Section>
            <Section title="Contract and notes" description="Agreement reference and internal remarks." icon="logs">
                {field('Contract ID', 'contract_id')}
                {field('Contract valid until', 'contract_valid_until', { type: 'date' })}
                <p className="span2 hint">Filled from the active <a href="/contracts">contract</a>.</p>
                <TextArea label="Notes/comments" disabled={disabled} {...bind(form, 'notes')} />
            </Section>
        </>
    );

    const sessionColumns = [
        { header: 'Started', cell: (r: Session) => r.start },
        { header: 'Ended', cell: (r: Session) => r.stop || <ConnectionState state={r.state} /> },
        { header: 'Lasted', cell: (r: Session) => r.time },
        { header: 'IP', cell: (r: Session) => <span className="mono">{r.ip}</span> },
        { header: 'Router', cell: (r: Session) => r.nas },
        { header: 'Download', className: 'num', cell: (r: Session) => r.down },
        { header: 'Upload', className: 'num', cell: (r: Session) => r.up },
        { header: 'Why it ended', cell: (r: Session) => r.cause },
    ];

    const tabs = [
        ...(client ? [{
            key: 'overview', title: 'Overview', icon: 'dashboard',
            content: (
                <>
                    <TrafficCard username={client.username} initial={client.traffic} />
                    <Panel title="Recent sessions">
                        <DataTable rows={client.sessions.slice(0, 6)} emptyText="No sessions yet." columns={sessionColumns} />
                    </Panel>
                </>
            ),
        }] : []),
        { key: 'account', title: 'Account', icon: 'lock', keepMounted: true, content: accountTab },
        { key: 'personal', title: 'Personal info', icon: 'user', keepMounted: true, content: personalTab },
        {
            key: 'accounting', title: 'Accounting', icon: 'signal',
            content: client ? (
                <>
                    <UsageCard username={client.username} initial={client.usage} />
                    <Panel title="Sessions">
                        <DataTable rows={client.sessions} exportRows columns={sessionColumns} />
                    </Panel>
                </>
            ) : <Empty>Session details appear after the client is saved and connects.</Empty>,
        },
        ...(client?.onu ? [{ key: 'onu', title: 'ONU', icon: 'fiber', content: <ClientOnu username={client.username} data={client.onu} canLink={canEdit} /> }] : []),
        {
            key: 'billing', title: 'Billing', icon: 'billing', count: client ? client.billing.invoices.length : undefined,
            content: client ? (
                <>
                    {client.billing.newInvoice && (
                        <div className="toolbar"><span className="toolbar-actions"><Link className="button primary" href={`/billing/invoices/create?username=${encodeURIComponent(client.username)}`}>New invoice</Link></span></div>
                    )}
                    <StatCards stats={client.billing.stats} className="client-stats billing-stats" />
                    {client.billing.overdue && <p className="billing-alert">{client.billing.overdue}</p>}
                    <Panel title="Invoices"><InvoiceTable invoices={client.billing.invoices} /></Panel>
                    <Panel title="Payments">
                        <DataTable
                            rows={client.billing.payments}
                            rowKey={(p) => p.id}
                            exportRows
                            columns={[
                                { header: 'Date', cell: (p) => p.date },
                                { header: 'Invoice', cell: (p) => <Link href={`/billing/invoices/${p.invoiceId}`}>{p.invoice}</Link> },
                                { header: 'Amount', cell: (p) => p.amount },
                                { header: 'Method', cell: (p) => p.method },
                                { header: 'Reference', cell: (p) => p.reference },
                                { header: 'Receipt', noExport: true, cell: (p) => <ReceiptActions invoiceId={p.invoiceId} /> },
                            ]}
                        />
                    </Panel>
                </>
            ) : <Empty>Save the client before creating invoices.</Empty>,
        },
        {
            key: 'documents', title: 'Documents', icon: 'image', count: client ? client.documents.length : undefined, keepMounted: true,
            content: (
                <>
                    {canUpload && (
                        <div className="form-grid">
                            {(['id_front', 'id_back'] as const).map((key) => (
                                <Field
                                    key={key}
                                    label={key === 'id_front' ? 'ID front' : 'ID back'}
                                    name={key}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    error={form.errors[key]}
                                    onChange={(e) => form.setData(key, e.target.files?.[0] ?? null)}
                                />
                            ))}
                        </div>
                    )}
                    {client && client.documents.length ? (
                        <DataTable
                            rows={client.documents}
                            rowKey={(d) => d.id}
                            columns={[
                                { header: 'Type', cell: (d) => d.type },
                                { header: 'Original name', cell: (d) => d.name },
                                { header: 'Mime', cell: (d) => d.mime },
                                { header: 'Uploaded', cell: (d) => d.uploaded },
                                { header: 'Action', cell: (d) => (can('documents.view') ? <><a href={`/documents/${d.id}/download`}>Download</a> · retained</> : '-') },
                            ]}
                        />
                    ) : <Empty>No documents uploaded.</Empty>}
                </>
            ),
        },
        {
            key: 'logs', title: 'Logs', icon: 'logs',
            content: (
                <DataTable
                    rows={client?.logs ?? []}
                    columns={[
                        { header: 'Actor', cell: (r) => r.actor },
                        { header: 'Action', cell: (r) => r.action },
                        { header: 'Entity', cell: (r) => r.entity },
                        { header: 'Time', cell: (r) => r.time },
                    ]}
                />
            ),
        },
    // A client that is not saved yet has no sessions, invoices or history to show.
    ].filter((t) => client || !['accounting', 'billing', 'logs'].includes(t.key));

    return (
        <AppLayout title={client ? client.header.name : 'New client'} heading={!client}>
            {client && <ProfileHeader username={client.username} header={client.header} />}
            {client?.revealed && (
                <Panel title={`Saved PPPoE password for ${client.username}`}>
                    <p className="revealed-secret"><code>{client.revealed}</code></p>
                    <p className="hint">This reveal was recorded in the audit log. The password is not stored in your browser history.</p>
                </Panel>
            )}
            <div className={`client-layout${client ? '' : ' is-new'}`}>
                <form
                    id="client-form"
                    tabIndex={-1}
                    onSubmit={(event) => { event.preventDefault(); void submit(); }}
                >
                    {draftRestored && (
                        <p className="recovery-notice">Your unsaved edits are back. Enter passwords and choose files again; nothing was saved yet.</p>
                    )}
                    <ErrorSummary errors={form.errors} />
                    <Tabs label="Client sections" tabs={tabs} defaultTab={tab} onChange={setTab} />
                    {formTab && <div className={canEdit ? 'form-actions sticky-actions' : 'form-actions'}>
                        {canEdit && <button className="primary" disabled={form.processing}>{client ? 'Save changes' : 'Create client'}</button>}
                        <Link className="button" href="/clients">Back to clients</Link>
                    </div>}
                </form>
                {client && (
                    <div className="client-side">
                        <OverviewPanel username={client.username} initial={client.overview} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
