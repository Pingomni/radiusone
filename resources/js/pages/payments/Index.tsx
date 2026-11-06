import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable, Pagination } from '@/components/table';
import { Badge, ListPanel } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { query } from '@/lib/format';
import type { Paginated } from '@/lib/types';

export type PaymentRow = {
    id: number; username: string; customer: string; provider: string; status: string; amount: string; currency: string;
    periodStart: string; periodEnd: string; billingPeriod: string; createdAt: string; paidAt: string; note: string; invoiceId: number | null; invoice: string;
};
type Filters = { q: string; status: string; provider: string; from: string; to: string };

const STATUS_LABEL: Record<string, string> = {
    paid: 'paid', pending: 'waiting', mismatch: 'needs review', reviewed: 'reviewed', canceled: 'canceled', expired: 'expired', failed: 'could not start',
};

export function PaymentStatus({ status }: { status: string }) {
    const tone = status === 'paid' ? 'success' : status === 'mismatch' || status === 'failed' ? 'danger' : status === 'pending' ? 'warning' : '';
    return <Badge tone={tone}>{STATUS_LABEL[status] ?? status}</Badge>;
}

const TABS: [string, string][] = [['', 'All'], ['paid', 'Paid'], ['pending', 'Waiting'], ['mismatch', 'Needs review'], ['reviewed', 'Reviewed'], ['closed', 'Not completed']];

export default function Index({ filters, providers, counts, metrics, result }: {
    filters: Filters; providers: Record<string, string>; counts: Record<string, number>; metrics: Metric[]; result: Paginated<PaymentRow>;
}) {
    const [q, setQ] = useState(filters.q);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const link = (next: Partial<Filters>) => `/payments${query({ ...filters, ...next })}`;
    return (
        <AppLayout title="Online payments">
            <MetricCards metrics={metrics} />
            <ListPanel
                title="Online payments"
                tools={<a className="button" href={`/payments/export${query(filters)}`}>Export CSV</a>}
            >
                <div className="billing-filters">
                    <nav className="segmented" aria-label="Payment status">
                        {TABS.map(([value, label]) => (
                            <Link key={value} href={link({ status: value })} aria-current={filters.status === value ? 'page' : undefined}>
                                {label}<span className="billing-count">{counts[value || 'all'] ?? 0}</span>
                            </Link>
                        ))}
                    </nav>
                    <form role="search" className="billing-search" onSubmit={(e) => { e.preventDefault(); router.get(link({ q, from, to })); }}>
                        <Icon name="search" />
                        <input type="search" name="q" aria-label="Search payments" placeholder="Search username, name, invoice or order" value={q} onChange={(e) => setQ(e.target.value)} />
                        <button type="submit" className="sr-only">Search</button>
                    </form>
                </div>
                <form className="payment-filters" onSubmit={(e) => { e.preventDefault(); router.get(link({ q, from, to })); }}>
                    <label>Service
                        <select value={filters.provider} onChange={(e) => router.get(link({ q, from, to, provider: e.target.value }))}>
                            <option value="">All</option>
                            {Object.entries(providers).map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                        </select>
                    </label>
                    <label>From<input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                    <label>To<input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
                    <button type="submit" className="button">Apply</button>
                    {(filters.q || filters.provider || filters.from || filters.to) && <Link className="link" href={`/payments${query({ status: filters.status })}`}>Clear</Link>}
                </form>
                <DataTable<PaymentRow>
                    rows={result.rows}
                    rowKey={(r) => r.id}
                    serverPaginated
                    emptyText="No online payments found."
                    columns={[
                        { header: 'Started', cell: (r) => <Link href={`/payments/${r.id}`}>{r.createdAt}</Link> },
                        { header: 'Customer', cell: (r) => <><Link href={`/clients/${encodeURIComponent(r.username)}/edit`}>{r.customer || r.username}</Link><br /><small className="muted">{r.username}</small></> },
                        { header: 'Amount', cell: (r) => `${r.amount} ${r.currency}` },
                        { header: 'Service', cell: (r) => r.provider },
                        { header: 'Covers', cell: (r) => `${r.periodStart} – ${r.periodEnd}` },
                        { header: 'Status', cell: (r) => <PaymentStatus status={r.status} /> },
                        { header: 'Invoice', cell: (r) => (r.invoiceId ? <Link href={`/billing/invoices/${r.invoiceId}`}>{r.invoice}</Link> : '') },
                    ]}
                />
                <Pagination result={result} path="/payments" query={filters} noun="payments" />
            </ListPanel>
        </AppLayout>
    );
}
