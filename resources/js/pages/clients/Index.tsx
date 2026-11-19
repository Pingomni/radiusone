import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type MouseEvent } from 'react';
import { DeleteButton, useConfirm } from '@/components/confirm';
import Icon from '@/components/Icon';
import { SignalReading } from '@/components/olt';
import { DataTable, ExportMenu, Pagination } from '@/components/table';
import { Badge, ListMenu, RowActions, Status, type StatusTone, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { query } from '@/lib/format';
import type { Option, Paginated } from '@/lib/types';

type Filters = { q: string; plan: string; nas: string; status: string };
type Row = {
    username: string; auth: TagData; customer: string; phone: string; plan: string; status: TagData; connection: TagData;
    ip: string; nas: string; mac: string; online: boolean; traffic: string; expiry: string; expiresIn: number | null; balance: string; owes: boolean;
    archived: boolean; name: string; live: 'online' | 'stale' | 'offline'; account: string;
};
type Counts = Record<'all' | 'online' | 'expiring' | 'expired' | 'suspended' | 'disabled' | 'archived', number>;
type PanelData = {
    username: string; name: string; phone: string;
    account: { label: string; tone: StatusTone };
    service: { name: string; speed: string; price: string };
    expiry: { date: string; days: number | null; tone: string };
    balance: { text: string; owes: boolean } | null;
    live: { ip: string; nas: string; mac: string; since: string; traffic: string } | null;
    onu: { rx: number | null; oltRx: number | null; weak: number; veryWeak: number; where: string; href: string; online: boolean } | null;
};

const VIEWS: [status: string, label: string, count: keyof Counts][] = [
    ['', 'All', 'all'], ['online', 'Online', 'online'], ['expiring', 'Expiring this week', 'expiring'], ['expired', 'Expired', 'expired'],
    ['suspended', 'Suspended', 'suspended'], ['disabled', 'Disabled', 'disabled'], ['archived', 'Archived', 'archived'],
];
const LIVE: Record<Row['live'], [StatusTone, string]> = { online: ['good', 'Online'], stale: ['warning', 'No recent update'], offline: ['neutral', 'Offline'] };
const ACCOUNT_TONE: Record<string, string> = { expired: 'warning', suspended: 'danger', disabled: 'danger', archived: '' };

const clean = (f: Filters) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''));
const profile = (username: string) => `/clients/${encodeURIComponent(username)}/edit`;
const relative = (days: number) => (days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : days > 0 ? `In ${days} days` : days === -1 ? 'Yesterday' : `${-days} days ago`);

function FilterBar({ filters, plans, nasOptions }: { filters: Filters; plans: Option[]; nasOptions: Option[] }) {
    const [values, setValues] = useState(filters);
    const apply = (next: Filters) => router.get('/clients', clean(next), { preserveScroll: true });
    const select = (name: 'plan' | 'nas', label: string, options: Option[]) => (
        <label className="list-filter">
            <span className="sr-only">{label}</span>
            <select name={name} aria-label={label} value={values[name]} onChange={(e) => { const next = { ...values, [name]: e.target.value }; setValues(next); apply(next); }}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        </label>
    );
    return (
        <form className="toolbar list-filterbar client-filterbar" role="search" onSubmit={(e) => { e.preventDefault(); apply(values); }}>
            <label className="list-search">
                <span className="sr-only">Search clients</span>
                <input type="search" name="q" aria-label="Search clients" placeholder="Name, username, phone, IP or MAC" value={values.q} onChange={(e) => setValues({ ...values, q: e.target.value })} />
            </label>
            {select('plan', 'Service', [{ value: '', label: 'Every service' }, ...plans])}
            {select('nas', 'Router', [{ value: '', label: 'Every router' }, ...nasOptions])}
            <div className="list-filter-buttons">
                <button>Search</button>
                {(filters.q || filters.plan || filters.nas) && <Link className="button" href={`/clients${query(clean({ ...filters, q: '', plan: '', nas: '' }))}`}>Clear</Link>}
            </div>
        </form>
    );
}

/** The side panel: one client at a glance, beside the list. Esc or the close button closes it. */
function ClientPanel({ username, onClose }: { username: string; onClose: () => void }) {
    const [data, setData] = useState<PanelData | null>(null);
    const [failed, setFailed] = useState(false);
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setFailed(false);
        fetch(`/clients/${encodeURIComponent(username)}/panel`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((d: PanelData) => setData(d))
            .catch((e: Error) => { if (e.name !== 'AbortError') setFailed(true); });
        return () => controller.abort();
    }, [username]);
    useEffect(() => { if (data) heading.current?.focus({ preventScroll: true }); }, [data]);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <aside className="client-panel" aria-label={`Client ${username}`} aria-busy={!data && !failed}>
            <button type="button" className="client-panel-close" onClick={onClose} aria-label="Close"><Icon name="close" /></button>
            {failed && <p className="field-error">This client could not be loaded. <Link href={profile(username)}>Open the profile</Link>.</p>}
            {!data && !failed && <p className="list-subline">Loading…</p>}
            {data && (
                <>
                    <div className="client-panel-head">
                        <h2 ref={heading} tabIndex={-1}>{data.name}</h2>
                        <div className="client-panel-meta">
                            <span className="mono">{data.username}</span>
                            <Status tone={data.live ? 'good' : 'neutral'}>{data.live ? 'Online' : 'Offline'}</Status>
                            {data.account.tone !== 'good' && <Status tone={data.account.tone}>{data.account.label}</Status>}
                        </div>
                    </div>
                    <div className="client-panel-actions">
                        <Link className="button primary" href={profile(data.username)}>Open profile</Link>
                        <Link className="button" href={`/clients/${encodeURIComponent(data.username)}/diagnostics`}>Diagnostics</Link>
                    </div>
                    <section>
                        <h3>Subscription</h3>
                        <p>{[data.service.name, data.service.price].filter(Boolean).join(', ') || '-'}</p>
                        {data.service.speed && <p className="list-subline">{data.service.speed}</p>}
                        {data.expiry.date && (
                            <p className="client-panel-row">
                                <span className="list-subline">Expires {data.expiry.date}</span>
                                {data.expiry.days !== null && <strong className={data.expiry.tone ? `${data.expiry.tone}-text` : undefined}>{relative(data.expiry.days)}</strong>}
                            </p>
                        )}
                    </section>
                    {data.balance && (
                        <section>
                            <h3>Balance</h3>
                            <p className={`client-panel-figure${data.balance.owes ? ' danger-text' : ''}`}>{data.balance.text}</p>
                        </section>
                    )}
                    <section>
                        <h3>Connection</h3>
                        {data.live ? (
                            <dl className="client-panel-facts">
                                <dt>IP address</dt><dd className="mono">{data.live.ip || '-'}</dd>
                                <dt>Router</dt><dd>{data.live.nas || '-'}</dd>
                                {data.live.mac && <><dt>MAC</dt><dd className="mono">{data.live.mac}</dd></>}
                                <dt>Since</dt><dd>{data.live.since.slice(0, 16)}</dd>
                                <dt>This session</dt><dd>{data.live.traffic}</dd>
                            </dl>
                        ) : <p className="list-subline">No session now.</p>}
                    </section>
                    {data.onu && (
                        <section>
                            <h3>Fibre signal</h3>
                            <SignalReading dbm={data.onu.rx} weak={data.onu.weak} veryWeak={data.onu.veryWeak} second={data.onu.oltRx === null ? undefined : `OLT ${data.onu.oltRx.toFixed(1).replace('-', '−')}`} />
                            <p className="list-subline"><Link href={data.onu.href}>{data.onu.where}</Link></p>
                        </section>
                    )}
                </>
            )}
        </aside>
    );
}

export default function Clients({ filters, counts, plans, nasOptions, result }: { filters: Filters; counts: Counts; plans: Option[]; nasOptions: Option[]; result: Paginated<Row> }) {
    const can = useCan();
    const ask = useConfirm();
    const [selected, setSelected] = useState<string[]>([]);
    const [action, setAction] = useState('');
    const [open, setOpen] = useState<string | null>(null);
    const bulkActions: Option[] = [
        ...(can('clients.manage') ? [{ value: 'suspend', label: 'Suspend' }, { value: 'disable', label: 'Disable' }] : []),
        ...(can('danger.delete') ? [{ value: 'archive', label: 'Archive' }] : []),
    ];
    const bulk = bulkActions.length > 0;
    const allOnPage = result.rows.length > 0 && result.rows.every((r) => selected.includes(r.username));
    const toggle = (username: string, on: boolean) => setSelected(on ? [...selected, username] : selected.filter((u) => u !== username));
    // A click on a row opens the side panel; links, buttons, boxes and menus inside it keep their own job.
    const onRowClick = (row: Row) => (event: MouseEvent<HTMLTableRowElement>) => {
        if ((event.target as HTMLElement).closest('a, button, input, select, summary, details, label')) return;
        setOpen(open === row.username ? null : row.username);
    };

    const columns = [
        ...(bulk ? [{
            header: <input type="checkbox" aria-label="Select all clients on this page" checked={allOnPage} onChange={(e) => setSelected(e.target.checked ? result.rows.map((r) => r.username) : [])} />,
            noExport: true,
            cell: (r: Row) => <input type="checkbox" aria-label={`Select ${r.username}`} checked={selected.includes(r.username)} onChange={(e) => toggle(r.username, e.target.checked)} />,
        }] : []),
        {
            header: 'Client',
            className: 'cell-wrap',
            cell: (r: Row) => (
                <div className="list-cell-stack">
                    <Link href={profile(r.username)}>{r.name}</Link>
                    <span className="list-subline"><span className="mono">{r.username}</span>{r.auth.text !== 'PPPoE' && <> <Badge>{r.auth.text}</Badge></>}</span>
                </div>
            ),
        },
        {
            header: 'Connection',
            cell: (r: Row) => (
                <div className="list-cell-stack list-badges">
                    <Status tone={LIVE[r.live][0]}>{LIVE[r.live][1]}</Status>
                    {r.account !== 'enabled' && <Badge tone={ACCOUNT_TONE[r.account] ?? ''}>{r.account.charAt(0).toUpperCase() + r.account.slice(1)}</Badge>}
                </div>
            ),
        },
        { header: 'Service', className: 'cell-wrap col-service', cell: (r: Row) => r.plan },
        { header: 'IP address', cell: (r: Row) => (r.ip || r.nas ? <div className="list-cell-stack"><span className="mono">{r.ip || '-'}</span>{r.nas && <span className="list-subline">{r.nas}</span>}</div> : '-') },
        {
            header: 'Expires',
            cell: (r: Row) => (r.expiresIn === null ? '-' : (
                <div className="list-cell-stack">
                    <span>{r.expiry}</span>
                    <span className={`list-subline${r.expiresIn < 0 ? ' danger-text' : r.expiresIn <= 7 ? ' warning-text' : ''}`}>{relative(r.expiresIn)}</span>
                </div>
            )),
        },
        { header: 'Traffic', className: 'num col-traffic', cell: (r: Row) => r.traffic },
        { header: 'Balance', className: 'num', cell: (r: Row) => <span className={r.owes ? 'danger-text' : 'is-empty-text'}>{r.balance}</span> },
        {
            header: <span className="sr-only">Actions</span>,
            noExport: true,
            cell: (r: Row) => (
                <RowActions label={`Actions for ${r.username}`}>
                    <button type="button" className="link" onClick={() => setOpen(r.username)}>Quick view</button>
                    {/* Disconnect needs the exact session and NAS, which only the profile shows. */}
                    {can('clients.disconnect') && r.online && <Link href={profile(r.username)}>Session / disconnect</Link>}
                    <Link href={`/clients/${encodeURIComponent(r.username)}/diagnostics`}>Diagnostics</Link>
                    {can('danger.delete') && !r.archived && (
                        <DeleteButton
                            action={`/clients/${encodeURIComponent(r.username)}/delete`}
                            label="Archive"
                            message={`Authentication is disabled for ${r.username}. Billing and document history are kept. Existing sessions are not disconnected.`}
                            typeToConfirm={r.username}
                            title={`Archive client ${r.username}?`}
                        />
                    )}
                </RowActions>
            ),
        },
    ];

    return (
        <AppLayout title="Clients" heading={false}>
            <div className="page-head with-actions">
                <div>
                    <h1>Clients</h1>
                    <p>{counts.all.toLocaleString('en-US')} clients, {counts.online.toLocaleString('en-US')} online now.</p>
                </div>
                <div className="page-actions">
                    <ExportMenu url={`/clients/export${query(filters)}`} total={result.total} loaded={result.rows.length} />
                    {can('clients.manage') && <Link className="button primary" href="/clients/create"><Icon name="plus" />New client</Link>}
                </div>
            </div>
            <div className={`clients-workspace${open ? ' has-panel' : ''}`}>
                <section className="panel clients-page clients-list" aria-label="Clients">
                    <nav className="view-tabs" aria-label="Client views">
                        {VIEWS.filter(([status, , key]) => status !== 'archived' || counts[key] > 0 || filters.status === 'archived').map(([status, label, key]) => (
                            <Link key={key} href={`/clients${query(clean({ ...filters, status }))}`} aria-current={filters.status === status ? 'page' : undefined} preserveScroll>
                                {label} <span className="view-count">{counts[key].toLocaleString('en-US')}</span>
                            </Link>
                        ))}
                    </nav>
                    <div className="clients-toolbar">
                        <FilterBar key={JSON.stringify(filters)} filters={filters} plans={plans} nasOptions={nasOptions} />
                        {bulk && (
                            <ListMenu summary={<>{selected.length > 0 ? <span role="status">{selected.length} selected</span> : 'Bulk actions'}</>} className="list-menu list-bulk-menu">
                                <div className="list-menu-options">
                                    <form
                                        className="bulk-actions"
                                        onSubmit={async (event) => {
                                            event.preventDefault();
                                            const ok = await ask({ title: 'Apply to the selected clients?', message: `${action ? bulkActions.find((o) => o.value === action)?.label : 'The action'} for ${selected.length} ${selected.length === 1 ? 'client' : 'clients'}.`, confirmLabel: 'Apply' });
                                            if (ok) router.post('/clients/bulk', { bulk_action: action, clients: selected }, { onSuccess: () => setSelected([]) });
                                        }}
                                    >
                                        <label>
                                            <span>Action</span>
                                            <select name="bulk_action" required value={action} onChange={(e) => setAction(e.target.value)}>
                                                <option value="">Choose</option>
                                                {bulkActions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                            </select>
                                        </label>
                                        <button className="primary" disabled={!selected.length}>Apply</button>
                                    </form>
                                </div>
                            </ListMenu>
                        )}
                    </div>
                    <DataTable
                        rows={result.rows}
                        rowKey={(r) => r.username}
                        columns={columns}
                        serverPaginated
                        resetHref="/clients"
                        emptyText={filters.status === 'expiring' ? 'No client expires in the next 7 days.' : 'No client matches.'}
                        rowProps={(r) => ({ className: `is-clickable${open === r.username ? ' is-selected' : ''}`, onClick: onRowClick(r) })}
                    />
                    <Pagination result={result} path="/clients" query={filters} noun="clients" />
                </section>
                {open && <ClientPanel key={open} username={open} onClose={() => setOpen(null)} />}
            </div>
        </AppLayout>
    );
}
