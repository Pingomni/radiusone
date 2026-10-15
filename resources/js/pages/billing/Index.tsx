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
import type { Option, Paginated } from '@/lib/types';

type Filters = { q: string; status: string };
type Row = InvoiceRow & { customer: string; service: string; canEdit: boolean; canVoid: boolean; canDelete: boolean };
type Payment = { id: number; date: string; invoiceId: number; invoice: string; username: string; amount: string; method: string; reference: string };

const STATUSES: Option[] = [
    { value: '', label: 'All statuses' }, { value: 'open', label: 'Unpaid' }, { value: 'partial', label: 'Partially paid' },
    { value: 'overdue', label: 'Overdue' }, { value: 'paid', label: 'Paid' }, { value: 'draft', label: 'Draft (not issued)' }, { value: 'void', label: 'Cancelled' },
];

function FilterBar({ filters }: { filters: Filters }) {
    const [values, setValues] = useState(filters);
    const apply = (next: Filters) => router.get('/billing', Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '')), { preserveScroll: true });
    return (
        <form className="toolbar list-filterbar" role="search" onSubmit={(e) => { e.preventDefault(); apply(values); }}>
            <label className="list-search">
                <span>Search invoices</span>
                <input type="search" name="q" aria-label="Search invoices or customers" placeholder="Invoice number or customer" value={values.q} onChange={(e) => setValues({ ...values, q: e.target.value })} />
            </label>
            <label className="list-filter">
                <span>Status</span>
                <select name="status" aria-label="Invoice status" value={values.status} onChange={(e) => { const next = { ...values, status: e.target.value }; setValues(next); apply(next); }}>
                    {STATUSES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </label>
            <div className="list-filter-buttons">
                <button>Filter</button>
                {Object.values(filters).some((v) => v !== '') && <Link className="button" href="/billing">Clear</Link>}
            </div>
        </form>
    );
}

export default function Billing({ filters, metrics, result, payments }: { filters: Filters; metrics: Metric[]; result: Paginated<Row>; payments: Payment[] }) {
    const can = useCan();
    const client = (username: string) => `/clients/${encodeURIComponent(username)}/edit`;

    return (
        <AppLayout title="Billing">
            <div className="clean-list billing-page">
                <MetricCards metrics={metrics} />
                <div className="billing-lists">
                    <ListPanel
                        title="Invoices"
                        description={`${result.total} matching ${result.total === 1 ? 'invoice' : 'invoices'}`}
                        tools={(
                            <>
                                <ExportMenu url={`/billing/export${query(filters)}`} total={result.total} loaded={result.rows.length} />
                                {can('billing.manage') && (
                                    <div className="list-create-actions">
                                        <PostButton action="/billing/generate" label="Generate recurring" className="button" confirm={{ title: 'Generate recurring invoices?', message: 'Generate recurring invoices for all billable clients?', confirmLabel: 'Generate' }} />
                                        <Link className="button primary" href="/billing/invoices/create"><Icon name="plus" />New invoice</Link>
                                    </div>
                                )}
                            </>
                        )}
                    >
                        <FilterBar key={JSON.stringify(filters)} filters={filters} />
                        <DataTable
                            rows={result.rows}
                            rowKey={(r) => r.id}
                            serverPaginated
                            resetHref="/billing"
                            columns={[
                                { header: 'Invoice', cell: (r) => <Link href={`/billing/invoices/${r.id}`}>{r.number}</Link> },
                                { header: 'Customer', cell: (r) => <Link href={client(r.username)}>{r.customer}</Link> },
                                { header: 'Service', cell: (r) => r.service },
                                { header: 'Issued', cell: (r) => r.issued },
                                { header: 'Due', cell: (r) => r.due },
                                { header: 'Total', cell: (r) => r.total },
                                { header: 'Paid', cell: (r) => r.paid },
                                { header: 'Balance', cell: (r) => r.balance },
                                { header: 'Status', cell: (r) => <InvoiceStatus status={r.status} /> },
                                {
                                    header: 'Actions',
                                    noExport: true,
                                    cell: (r) => (
                                        <div className="list-row-actions">
                                            <Link className="button" href={`/billing/invoices/${r.id}`}>View</Link>
                                            <RowActions label={`More actions for ${r.number}`}>
                                                <InvoiceDocuments invoice={r} />
                                                {r.canEdit && <Link href={`/billing/invoices/${r.id}/edit`}>edit</Link>}
                                                {r.canVoid && <PostButton action={`/billing/invoices/${r.id}/void`} label="cancel" confirm={{ title: `Cancel ${r.number}?`, message: 'Cancel this invoice? It will be marked void.', confirmLabel: 'Cancel invoice' }} />}
                                                {r.canDelete && <PostButton action={`/billing/invoices/${r.id}/delete`} label="delete draft" confirm={{ title: `Delete ${r.number}?`, message: 'Delete this unpaid draft permanently?', confirmLabel: 'Delete draft' }} />}
                                            </RowActions>
                                        </div>
                                    ),
                                },
                            ]}
                        />
                        <Pagination result={result} path="/billing" query={filters} noun="invoices" />
                    </ListPanel>
                    <ListPanel title="Recent Payments" description="Most recent payments">
                        <DataTable
                            rows={payments}
                            rowKey={(p) => p.id}
                            exportRows={payments.length > 0}
                            emptyText={<div className="list-empty"><strong>No payments recorded</strong><p>Payments will appear here once they are recorded against an invoice.</p></div>}
                            columns={[
                                { header: 'Date', cell: (p) => p.date },
                                { header: 'Invoice', cell: (p) => <Link href={`/billing/invoices/${p.invoiceId}`}>{p.invoice}</Link> },
                                { header: 'Customer', cell: (p) => <Link href={client(p.username)}>{p.username}</Link> },
                                { header: 'Amount', cell: (p) => p.amount },
                                { header: 'Method', cell: (p) => p.method },
                                { header: 'Reference', cell: (p) => p.reference },
                            ]}
                        />
                    </ListPanel>
                </div>
            </div>
        </AppLayout>
    );
}
