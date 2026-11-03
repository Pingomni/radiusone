import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatCards } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import { ChartOrMessage, type ChartPayload } from '@/components/LineChart';
import { LivePanel } from '@/components/LivePanel';
import { OnuPower, OnuStatus, type OnuRow } from '@/components/olt';
import { DataTable, Pagination } from '@/components/table';
import { Field, ListPanel, Select, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import type { Paginated } from '@/lib/types';

type Olt = { id: number; name: string; model: string; host: string; enabled: boolean; telnet: boolean; lastSync: string | null; lastMacSync: string | null; syncRequested: boolean; error: string; macError: string };
type Counts = { total: number; online: number; offline: number; weak: number };

type History = { range: string; ranges: Record<string, string>; url: string; data: ChartPayload };

export default function OltShow({ olt, counts, filters, history, result }: { olt: Olt; counts: Counts; filters: { q: string; status: string }; history: History; result: Paginated<OnuRow> }) {
    const can = useCan();
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const path = `/olts/${olt.id}`;
    return (
        <AppLayout title={olt.name}>
            <div className="toolbar">
                <Link className="button" href="/olts">All OLTs</Link>
                <Link className="button" href="/olts/mapping">Client mapping</Link>
                {can('nas.manage') && <PostButton action={`${path}/sync`} label={olt.syncRequested ? 'Read requested' : 'Read now'} className="button" disabled={olt.syncRequested} />}
                {can('nas.manage') && <Link className="button" href={`${path}/edit`}>Edit</Link>}
            </div>
            {olt.error && <p className="flash danger" role="alert">SNMP read failed: {olt.error}</p>}
            {olt.macError && <p className="flash warning" role="alert">Telnet read failed: {olt.macError}</p>}
            <StatCards stats={[
                { label: 'ONUs', value: String(counts.total), note: olt.model, tone: '' },
                { label: 'Online', value: String(counts.online), note: olt.host, tone: '' },
                { label: 'Offline', value: String(counts.offline), note: '', tone: counts.offline ? 'warning' : '' },
                { label: 'Weak signal', value: String(counts.weak), note: 'Below -27 dBm', tone: counts.weak ? 'warning' : '' },
            ]} />
            <p className="hint">
                ONU list read <Time value={olt.lastSync} /> (every 5 minutes).
                {olt.telnet ? <> MAC table read <Time value={olt.lastMacSync} /> (every 15 minutes).</> : ' Telnet is off, so clients are not found automatically from their MAC.'}
            </p>
            <div className="toolbar traffic-filters">
                <nav className="segmented" aria-label="Time range">
                    {Object.entries(history.ranges).map(([key, label]) => (
                        <Link key={key} href={`${path}?range=${key}`} preserveScroll aria-current={key === history.range ? 'page' : undefined}>{label}</Link>
                    ))}
                </nav>
            </div>
            <LivePanel title="ONUs over time" url={history.url} interval={60} initial={history.data} className="traffic-panel" render={(payload) => <ChartOrMessage payload={payload} />} />
            <ListPanel
                title="ONUs"
                description={`${result.total} matching`}
                tools={(
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.get(path, { q, status }, { preserveState: true }); }}>
                        <Field label="Search" name="q" placeholder="Serial or client" value={q} onChange={(e) => setQ(e.target.value)} />
                        <Select label="Status" name="status" options={{ '': 'All', online: 'Online', offline: 'Offline' }} value={status} onChange={(e) => setStatus(e.target.value)} />
                        <button className="button">Filter</button>
                    </form>
                )}
            >
                <DataTable
                    rows={result.rows}
                    rowKey={(o) => o.id}
                    serverPaginated
                    exportRows
                    emptyText={counts.total ? 'No ONU matches.' : 'No ONUs read yet.'}
                    columns={[
                        { header: 'Port', cell: (o) => <span className="mono">{o.position}</span> },
                        { header: 'Serial', cell: (o) => <span className="mono">{o.sn}</span> },
                        { header: 'Description', className: 'wrap', cell: (o) => o.description || '-' },
                        { header: 'Client', cell: (o) => (o.clients.length ? o.clients.map((u) => <div key={u}><Link href={`/clients/${encodeURIComponent(u)}/edit?tab=onu`}>{u}</Link></div>) : '-') },
                        { header: 'Status', cell: (o) => <OnuStatus status={o.status} /> },
                        { header: 'Signal at ONU', cell: (o) => <OnuPower dbm={o.rx} /> },
                        { header: 'Signal at OLT', cell: (o) => <OnuPower dbm={o.oltRx} /> },
                        { header: 'Distance', cell: (o) => (o.distance === null ? '-' : `${o.distance} m`) },
                        { header: 'Last offline', className: 'wrap', cell: (o) => (o.lastDown || o.downCause ? <>{o.lastDown && <Time value={o.lastDown} />}{o.downCause && <div>{o.downCause}</div>}</> : '-') },
                        { header: 'Model', cell: (o) => o.model || '-' },
                    ]}
                />
                <Pagination result={result} path={path} query={filters} noun="ONUs" />
            </ListPanel>
        </AppLayout>
    );
}
