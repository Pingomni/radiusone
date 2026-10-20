import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { InvoiceDocuments, InvoiceStatus, type InvoiceRow } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable, ExportMenu, Pagination } from '@/components/table';
import { ListPanel, RowActions } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { query } from '@/lib/format';
import type { Paginated } from '@/lib/types';

type Filters = { q: string; status: string };
type StatusTab = { value: string; label: string; count: number };
type Row = InvoiceRow & { customer: string; service: string; owed: boolean; canEdit: boolean; canVoid: boolean; canDelete: boolean };
type Payment = { id: number; date: string; invoiceId: number; invoice: string; username: string; customer: string; amount: string; method: string };

const link = (filters: Filters) => `/billing${query(filters)}`;

/** Status tabs with counts, plus one search box. */
function Filters({ filters, statuses }: { filters: Filters; statuses: StatusTab[] }) {
    const [q, setQ] = useState(filters.q);
    return (
        <div className="billing-filters">
            <nav className="segmented" aria-label="Invoice status">
                {statuses.map((s) => (
                    <Link key={s.value} href={link({ ...filters, status: s.value })} aria-current={filters.status === s.value ? 'page' : undefined} preserveScroll>
                        {s.label}<span className="billing-count">{s.count}</span>
                    </Link>
                ))}
            </nav>
            <form role="search" className="billing-search" onSubmit={(e) => { e.preventDefault(); router.get(link({ ...filters, q }), {}, { preserveScroll: true }); }}>
                <Icon name="search" />
                <input type="search" name="q" aria-label="Search invoices" placeholder="Search invoice number or customer" value={q} onChange={(e) => setQ(e.target.value)} />
            </form>
        </div>
    );
}

export default function Billing({ filters, metrics, statuses, result, payments }: {
    filters: Filters; metrics: Metric[]; statuses: StatusTab[]; result: Paginated<Row>; payments: Payment[];
}) {
    const can = useCan();
    const manage = can('billing.manage');
    const client = (username: string) => `/clients/${encodeURIComponent(username)}/edit`;
    const filtered = filters.q !== '' || filters.status !== '';

    return (
        <AppLayout title="Billing">
            <div className="clean-list billing-page">
                <MetricCards metrics={metrics} />
                <ListPanel
                    title="Invoices"
                    description={filtered ? `${result.total} matching` : undefined}
                    tools={(
                        <>
                            <ExportMenu url={`/billing/export${query(filters)}`} total={result.total} loaded={result.rows.length} />
                            {manage && <Link className="button primary" href="/billing/invoices/create"><Icon name="plus" />New invoice</Link>}
                        </>
                    )}
                >
                    <Filters key={JSON.stringify(filters)} filters={filters} statuses={statuses} />
                    <DataTable
                        rows={result.rows}
                        rowKey={(r) => r.id}
                        serverPaginated
                        resetHref={filtered ? '/billing' : undefined}
                        emptyText={<div className="list-empty"><strong>{filtered ? 'No invoices match' : 'No invoices yet'}</strong><p>{filtered ? 'Try another status or search.' : 'Create one with New invoice, or wait for the nightly run.'}</p></div>}
                        columns={[
                            { header: 'Invoice', cell: (r) => <><Link href={`/billing/invoices/${r.id}`}><strong>{r.number}</strong></Link><span className="list-subline">Issued {r.issued}</span></> },
                            { header: 'Customer', cell: (r) => <><Link href={client(r.username)}>{r.customer}</Link>{r.service && <span className="list-subline">{r.service}</span>}</> },
                            { header: 'Due', cell: (r) => r.due },
                            { header: 'Amount', cell: (r) => <><strong>{r.total}</strong>{r.owed && r.status.paid > 0 && <span className="list-subline">{r.balance} left</span>}</> },
                            { header: 'Status', cell: (r) => <InvoiceStatus status={r.status} /> },
                            {
                                header: '',
                                noExport: true,
                                cell: (r) => (
                                    <div className="list-row-actions">
                                        {manage && r.owed
                                            ? <Link className="button" href={`/billing/invoices/${r.id}#record-payment`}>Record payment</Link>
                                            : <Link className="button" href={`/billing/invoices/${r.id}`}>Open</Link>}
                                        <RowActions label={`More actions for ${r.number}`}>
                                            <InvoiceDocuments invoice={r} />
                                            {r.canEdit && <Link href={`/billing/invoices/${r.id}/edit`}>Edit</Link>}
                                            {r.canVoid && <PostButton action={`/billing/invoices/${r.id}/void`} label="Cancel invoice" confirm={{ title: `Cancel ${r.number}?`, message: 'Cancel this invoice? It will be marked void.', confirmLabel: 'Cancel invoice' }} />}
                                            {r.canDelete && <PostButton action={`/billing/invoices/${r.id}/delete`} label="Delete draft" confirm={{ title: `Delete ${r.number}?`, message: 'Delete this unpaid draft permanently?', confirmLabel: 'Delete draft' }} />}
                                        </RowActions>
                                    </div>
                                ),
                            },
                        ]}
                    />
                    <Pagination result={result} path="/billing" query={filters} noun="invoices" />
                </ListPanel>
                {payments.length > 0 && (
                    <ListPanel title="Latest payments">
                        <ul className="billing-payments">
                            {payments.map((p) => (
                                <li key={p.id}>
                                    <div>
                                        <Link href={client(p.username)}>{p.customer}</Link>
                                        <span className="list-subline">{p.date} · {p.method} · <Link href={`/billing/invoices/${p.invoiceId}`}>{p.invoice}</Link></span>
                                    </div>
                                    <strong>{p.amount}</strong>
                                </li>
                            ))}
                        </ul>
                    </ListPanel>
                )}
            </div>
        </AppLayout>
    );
}
