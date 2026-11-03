import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatCards } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import { OnuPower, OnuStatus } from '@/components/olt';
import { DataTable, Pagination } from '@/components/table';
import { Badge, Field, ListPanel, Select } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import type { Paginated } from '@/lib/types';

type Row = {
    username: string;
    customer: string;
    link: { sn: string; olt: string; position: string | null; status: string; rx: number | null; source: string } | null;
    found: { mac: string; onus: { sn: string; position: string }[] } | null;
};
type Counts = { all: number; linked: number; unlinked: number; suggested: number; differs: number };

const FILTERS = { unlinked: 'No ONU yet', suggested: 'ONU found, not linked', differs: 'Session points elsewhere', linked: 'Linked', all: 'All clients' };

export default function OltMapping({ filters, counts, canLink, result }: { filters: { q: string; filter: string }; counts: Counts; canLink: boolean; result: Paginated<Row> }) {
    const [q, setQ] = useState(filters.q);
    const [filter, setFilter] = useState(filters.filter);
    const client = (u: string) => `/clients/${encodeURIComponent(u)}`;
    return (
        <AppLayout title="Client mapping">
            <div className="toolbar"><Link className="button" href="/olts">All OLTs</Link></div>
            <StatCards stats={[
                { label: 'Linked', value: String(counts.linked), note: `of ${counts.all} clients`, tone: '' },
                { label: 'No ONU yet', value: String(counts.unlinked), note: '', tone: counts.unlinked ? 'warning' : '' },
                { label: 'Session points elsewhere', value: String(counts.differs), note: 'Check these', tone: counts.differs ? 'warning' : '' },
            ]} />
            <ListPanel
                title="Clients and their ONUs"
                description="A client is linked automatically once, when its session MAC is behind exactly one ONU, or when an ONU's description is exactly its username. A link is never replaced automatically."
                tools={(
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.get('/olts/mapping', { q, filter }, { preserveState: true }); }}>
                        <Field label="Search" name="q" placeholder="Username or name" value={q} onChange={(e) => setQ(e.target.value)} />
                        <Select label="Show" name="filter" options={FILTERS} value={filter} onChange={(e) => setFilter(e.target.value)} />
                        <button className="button">Filter</button>
                    </form>
                )}
            >
                <DataTable
                    rows={result.rows}
                    rowKey={(r) => r.username}
                    serverPaginated
                    emptyText="No client matches."
                    columns={[
                        { header: 'Client', cell: (r) => <div className="list-cell-stack"><Link href={`${client(r.username)}/edit?tab=onu`}>{r.username}</Link><span>{r.customer}</span></div> },
                        {
                            header: 'Linked ONU',
                            cell: (r) => (r.link ? (
                                <div className="list-cell-stack">
                                    <span className="mono">{r.link.sn}</span>
                                    <span>{r.link.olt} {r.link.position && <span className="mono">{r.link.position}</span>}</span>
                                    <span className="hint">{r.link.source}</span>
                                </div>
                            ) : '-'),
                        },
                        { header: 'Status', cell: (r) => (r.link ? (r.link.status === 'missing' ? <Badge tone="warning">Not on the OLT</Badge> : <OnuStatus status={r.link.status} />) : '-') },
                        { header: 'Signal', cell: (r) => (r.link ? <OnuPower dbm={r.link.rx} /> : '-') },
                        {
                            header: 'Found from the session',
                            className: 'wrap',
                            cell: (r) => (r.found ? (
                                <div className="list-cell-stack">
                                    <span className="mono">{r.found.mac}</span>
                                    {r.found.onus.map((o) => (
                                        <span key={o.sn}>
                                            <span className="mono">{o.sn} ({o.position})</span>{' '}
                                            {canLink && <PostButton action={`${client(r.username)}/onu`} data={{ sn: o.sn }} label="Link" className="link" />}
                                        </span>
                                    ))}
                                </div>
                            ) : '-'),
                        },
                    ]}
                />
                <Pagination result={result} path="/olts/mapping" query={filters} noun="clients" />
            </ListPanel>
        </AppLayout>
    );
}
