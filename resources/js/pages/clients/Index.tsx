import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { DeleteButton, useConfirm } from '@/components/confirm';
import Icon from '@/components/Icon';
import { DataTable, ExportMenu, Pagination } from '@/components/table';
import { ListMenu, ListPanel, RowActions, Tag, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { query } from '@/lib/format';
import type { Option, Paginated } from '@/lib/types';

type Filters = { q: string; plan: string; nas: string; status: string };
type Row = {
    username: string; auth: TagData; customer: string; phone: string; plan: string; status: TagData; connection: TagData;
    ip: string; nas: string; mac: string; online: boolean; traffic: string; expiry: string; balance: string; archived: boolean;
};

const STATUSES: Option[] = [
    { value: '', label: 'All statuses' }, { value: 'online', label: 'Online now' }, { value: 'offline', label: 'Offline' },
    { value: 'enabled', label: 'Enabled' }, { value: 'disabled', label: 'Disabled' }, { value: 'expired', label: 'Expired' },
    { value: 'suspended', label: 'Suspended' }, { value: 'archived', label: 'Archived' },
];

function FilterBar({ filters, plans, nasOptions }: { filters: Filters; plans: Option[]; nasOptions: Option[] }) {
    const [values, setValues] = useState(filters);
    const apply = (next: Filters) => router.get('/clients', Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '')), { preserveScroll: true });
    const select = (name: keyof Filters, label: string, options: Option[]) => (
        <label className="list-filter">
            <span>{label}</span>
            <select name={name} aria-label={`Client ${label.toLowerCase()}`} value={values[name]} onChange={(e) => { const next = { ...values, [name]: e.target.value }; setValues(next); apply(next); }}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        </label>
    );
    return (
        <form className="toolbar list-filterbar client-filterbar" role="search" onSubmit={(e) => { e.preventDefault(); apply(values); }}>
            <label className="list-search">
                <span>Search customers</span>
                <input type="search" name="q" aria-label="Search clients" placeholder="Name, username or phone" value={values.q} onChange={(e) => setValues({ ...values, q: e.target.value })} />
            </label>
            {select('plan', 'Service plan', [{ value: '', label: 'All plans' }, ...plans])}
            {select('nas', 'NAS device', [{ value: '', label: 'All NAS' }, ...nasOptions])}
            {select('status', 'Status', STATUSES)}
            <div className="list-filter-buttons">
                <button>Filter</button>
                {Object.values(filters).some((v) => v !== '') && <Link className="button" href="/clients">Clear</Link>}
            </div>
        </form>
    );
}

export default function Clients({ filters, plans, nasOptions, result }: { filters: Filters; plans: Option[]; nasOptions: Option[]; result: Paginated<Row> }) {
    const can = useCan();
    const ask = useConfirm();
    const [selected, setSelected] = useState<string[]>([]);
    const [action, setAction] = useState('');
    const bulkActions: Option[] = [
        ...(can('clients.manage') ? [{ value: 'suspend', label: 'Suspend' }, { value: 'disable', label: 'Disable' }] : []),
        ...(can('danger.delete') ? [{ value: 'archive', label: 'Archive' }] : []),
    ];
    const bulk = bulkActions.length > 0;
    const allOnPage = result.rows.length > 0 && result.rows.every((r) => selected.includes(r.username));
    const toggle = (username: string, on: boolean) => setSelected(on ? [...selected, username] : selected.filter((u) => u !== username));

    const columns = [
        ...(bulk ? [{
            header: <input type="checkbox" aria-label="Select all clients on this page" checked={allOnPage} onChange={(e) => setSelected(e.target.checked ? result.rows.map((r) => r.username) : [])} />,
            noExport: true,
            cell: (r: Row) => <input type="checkbox" aria-label={`Select ${r.username}`} checked={selected.includes(r.username)} onChange={(e) => toggle(r.username, e.target.checked)} />,
        }] : []),
        {
            header: 'Username',
            cell: (r: Row) => <div className="list-cell-stack"><Link href={`/clients/${encodeURIComponent(r.username)}/edit`}>{r.username}</Link><span className="list-subline"><Tag tag={r.auth} /></span></div>,
        },
        { header: 'Customer', className: 'cell-wrap', cell: (r: Row) => <div className="list-cell-stack"><span>{r.customer}</span>{r.phone && <span className="list-subline">{r.phone}</span>}</div> },
        { header: 'Plan', className: 'cell-wrap', cell: (r: Row) => r.plan },
        { header: 'Status', cell: (r: Row) => <div className="list-cell-stack list-badges"><Tag tag={r.status} /><Tag tag={r.connection} /></div> },
        {
            header: 'IP / NAS',
            cell: (r: Row) => {
                const parts = ([['IP', r.ip], ['NAS', r.nas], ['MAC', r.mac]] as const).filter(([, v]) => v);
                return parts.length ? <div className="list-cell-stack">{parts.map(([label, value]) => <span key={label}><span className="list-cell-caption">{label}</span> {value}</span>)}</div> : '—';
            },
        },
        { header: 'Traffic', cell: (r: Row) => r.traffic },
        { header: 'Expiry', cell: (r: Row) => r.expiry },
        { header: 'Balance', cell: (r: Row) => r.balance },
        {
            header: 'Actions',
            noExport: true,
            cell: (r: Row) => (
                <RowActions label={`Actions for ${r.username}`}>
                    {/* Disconnect needs the exact session and NAS, which only the profile shows. */}
                    {can('clients.disconnect') && r.online && <Link href={`/clients/${encodeURIComponent(r.username)}/edit`}>Session / disconnect</Link>}
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
        <AppLayout title="Clients">
            <div className="clean-list clients-page">
                <ListPanel
                    title="Customers"
                    description={`${result.total} matching ${result.total === 1 ? 'customer' : 'customers'}`}
                    tools={(
                        <>
                            {bulk && (
                                <ListMenu summary={<>Bulk actions {selected.length > 0 && <span role="status">{selected.length} selected</span>}</>} className="list-menu list-bulk-menu">
                                    <div className="list-menu-options">
                                        <form
                                            className="bulk-actions"
                                            onSubmit={async (event) => {
                                                event.preventDefault();
                                                const ok = await ask({ title: 'Apply bulk action?', message: 'The selected action is applied to every selected client on this page.', confirmLabel: 'Apply' });
                                                if (ok) router.post('/clients/bulk', { bulk_action: action, clients: selected }, { onSuccess: () => setSelected([]) });
                                            }}
                                        >
                                            <label>
                                                <span>Bulk action</span>
                                                <select name="bulk_action" required value={action} onChange={(e) => setAction(e.target.value)}>
                                                    <option value="">Choose action</option>
                                                    {bulkActions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                                </select>
                                            </label>
                                            <button className="primary" disabled={!selected.length}>Apply</button>
                                        </form>
                                    </div>
                                </ListMenu>
                            )}
                            <ExportMenu url={`/clients/export${query(filters)}`} total={result.total} loaded={result.rows.length} />
                            {can('clients.manage') && <Link className="button primary" href="/clients/create"><Icon name="plus" />New client</Link>}
                        </>
                    )}
                >
                    <FilterBar key={JSON.stringify(filters)} filters={filters} plans={plans} nasOptions={nasOptions} />
                    <DataTable rows={result.rows} rowKey={(r) => r.username} columns={columns} exportRows serverPaginated resetHref="/clients" />
                    <Pagination result={result} path="/clients" query={filters} noun="customers" />
                </ListPanel>
            </div>
        </AppLayout>
    );
}
