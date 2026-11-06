import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { DataTable } from '@/components/table';
import { Badge, ListPanel } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { query } from '@/lib/format';

export type ContractRow = {
    id: number; number: string; username: string; customer: string; service: string; monthlyPrice: string; currency: string;
    period: string; start: string; end: string; term: number; status: 'active' | 'ended' | 'cancelled';
};
type Filters = { q: string; status: string };

export const statusTone = (status: string) => (status === 'active' ? 'success' : status === 'cancelled' ? 'danger' : '');

const STATUSES = [['', 'All'], ['active', 'Active'], ['ended', 'Ended'], ['cancelled', 'Cancelled']];

export default function Index({ filters, rows, canManage }: { filters: Filters; rows: ContractRow[]; canManage: boolean }) {
    const [q, setQ] = useState(filters.q);
    const link = (next: Filters) => `/contracts${query(next)}`;
    return (
        <AppLayout title="Contracts">
            <ListPanel
                title="Contracts"
                tools={canManage ? <Link className="button primary" href="/contracts/create"><Icon name="plus" />New contract</Link> : undefined}
            >
                <div className="billing-filters">
                    <nav className="segmented" aria-label="Contract status">
                        {STATUSES.map(([value, label]) => (
                            <Link key={value} href={link({ ...filters, status: value })} aria-current={filters.status === value ? 'page' : undefined}>{label}</Link>
                        ))}
                    </nav>
                    <form role="search" className="billing-search" onSubmit={(e) => { e.preventDefault(); router.get(link({ ...filters, q })); }}>
                        <Icon name="search" />
                        <input type="search" name="q" aria-label="Search contracts" placeholder="Search number, username or name" value={q} onChange={(e) => setQ(e.target.value)} />
                    </form>
                </div>
                <DataTable<ContractRow>
                    rows={rows}
                    rowKey={(r) => r.id}
                    emptyText="No contracts yet."
                    columns={[
                        { header: 'Contract', cell: (r) => <Link href={`/contracts/${r.id}`}>{r.number}</Link> },
                        { header: 'Customer', cell: (r) => <><Link href={`/clients/${encodeURIComponent(r.username)}/edit`}>{r.customer || r.username}</Link><br /><small className="muted">{r.username}</small></> },
                        { header: 'Plan', cell: (r) => r.service },
                        { header: 'Billed', cell: (r) => r.period },
                        { header: 'Term', cell: (r) => `${r.start} to ${r.end}` },
                        { header: 'Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                    ]}
                />
            </ListPanel>
        </AppLayout>
    );
}
