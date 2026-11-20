import { Link, useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { InvoiceTable, StatCards, type InvoiceRow, type Stat } from '@/components/billing';
import { PostButton, useConfirm } from '@/components/confirm';
import Icon from '@/components/Icon';
import { LivePanel } from '@/components/LivePanel';
import { DataTable } from '@/components/table';
import ClientOnu, { type ClientOnuData } from '@/components/ClientOnu';
import { Tabs } from '@/components/tabs';
import { UsageCard, type UsageHistory } from '@/components/usage';
import { Check, ConnectionState, Empty, ErrorSummary, Field, Panel, Select, Tag, TextArea, type TagData, State } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { bind, bindCheck } from '@/lib/form';
import { bytes, randomPassword } from '@/lib/format';

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
    weeklyTraffic: { day: string; down: number; up: number }[];
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
function TrafficGraph({ rows }: { rows: { day: string; down: number; up: number }[] }) {
    if (!rows.length) return <div className="traffic-graph empty">No session counters updated in the last 7 days.</div>;
    const max = Math.max(1, ...rows.flatMap((r) => [r.down, r.up]));
    const [left, top, plotWidth, plotHeight] = [62, 32, 640, 145];
    const bottom = top + plotHeight;
    const step = rows.length > 1 ? plotWidth / (rows.length - 1) : 0;
    const xAt = (i: number) => (rows.length > 1 ? left + i * step : left + plotWidth / 2);
    const yAt = (v: number) => bottom - (v / max) * plotHeight;
    const down = rows.map((r, i) => `${xAt(i).toFixed(1)},${yAt(r.down).toFixed(1)}`);
    const up = rows.map((r, i) => `${xAt(i).toFixed(1)},${yAt(r.up).toFixed(1)}`);
    const avg = (key: 'down' | 'up') => rows.reduce((sum, r) => sum + r[key], 0) / rows.length;
    const peak = (key: 'down' | 'up') => Math.max(...rows.map((r) => r[key]));
    const latest = rows[rows.length - 1];
    return (
        <div className="traffic-graph">
            <svg viewBox="0 0 760 250" role="img" aria-label="Cumulative session counters grouped by last update date">
                <text className="traffic-axis" x="18" y={top + plotHeight / 2} transform={`rotate(-90 18 ${top + plotHeight / 2})`}>Data</text>
                <rect className="traffic-plot" x={left} y={top} width={plotWidth} height={plotHeight} />
                <g className="traffic-grid">
                    {[0, 1, 2, 3, 4].map((i) => {
                        const y = top + (plotHeight / 4) * i;
                        return (
                            <g key={i}>
                                <line x1={left} y1={y} x2={left + plotWidth} y2={y} />
                                <text x="54" y={y + 4} textAnchor="end">{bytes(Math.round(max - (max / 4) * i))}</text>
                            </g>
                        );
                    })}
                </g>
                <path className="traffic-down-area" d={`M ${xAt(0)} ${bottom} L ${down.join(' L ')} L ${xAt(rows.length - 1)} ${bottom} Z`} />
                <polyline className="traffic-down-line" points={down.join(' ')} />
                <polyline className="traffic-up-line" points={up.join(' ')} />
                <g className="traffic-labels">{rows.map((r, i) => <text key={r.day} x={xAt(i)} y="198" textAnchor="middle">{r.day.slice(5)}</text>)}</g>
            </svg>
            <div className="traffic-legend">
                <span><i className="legend-down" />Download: latest {bytes(latest.down)}, average {bytes(Math.round(avg('down')))}, peak {bytes(peak('down'))}</span>
                <span><i className="legend-up" />Upload: latest {bytes(latest.up)}, average {bytes(Math.round(avg('up')))}, peak {bytes(peak('up'))}</span>
            </div>
        </div>
    );
}

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
    const ask = useConfirm();
    const form = useForm<Values>({ ...values, id_front: null, id_back: null });
    const [tab, setTab] = useState(draftTab ?? (typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null) ?? (client ? 'overview' : 'account'));
    // Overview, Accounting, Billing and Logs are read-only views: the form's Save bar does not apply there.
    const formTab = !['overview', 'accounting', 'billing', 'logs', 'onu'].includes(tab);
    const [generated, setGenerated] = useState(false);
    const disabled = !canEdit;
    const dhcp = form.data.auth_method === 'dhcp';
    const field = (label: string, key: string, props: Record<string, unknown> = {}) => <Field label={label} disabled={disabled} {...bind(form, key)} {...props} />;

    const submit = async () => {
        if (client && form.data.username !== values.username) {
            const ok = await ask({ title: 'Rename this client?', message: 'Renaming changes the RADIUS identity. Accounting history moves to the new name, but a live session keeps the old name until it reconnects.', confirmLabel: 'Rename and save' });
            if (!ok) return;
        }
        // Unchecked boxes and empty file inputs are left out, as a browser form post would.
        form.transform((data) => Object.fromEntries(Object.entries({ ...data, _active_tab: tab }).filter(([, v]) => v !== false && v !== null)));
        form.post('/clients/save', { forceFormData: true, preserveScroll: true, onSuccess: () => setGenerated(false), onFinish: () => form.setData('password', '') });
    };

    const accountTab = (
        <>
            <Section title="Credentials" description="How the customer authenticates against RADIUS." icon="lock">
                <Select label="Authentication" disabled={disabled} options={{ pppoe: 'PPPoE username/password', dhcp: 'DHCP MAC address' }} {...bind(form, 'auth_method')} />
                {field('Username', 'username', { hint: 'For DHCP this is saved as the MAC address.', readOnly: dhcp, value: dhcp ? String(form.data.mac_address ?? '') : form.data.username })}
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
                {field('CPE MAC address', 'mac_address', { required: dhcp })}
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
                    <UsageCard username={client.username} initial={client.usage} />
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
                    <Panel title="Session totals by last update date, 7 days">
                        <TrafficGraph rows={client.weeklyTraffic} />
                    </Panel>
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
