import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatCards } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import { ChartOrMessage, type ChartPayload } from '@/components/LineChart';
import { LivePanel } from '@/components/LivePanel';
import { OnuPower, OnuStatus, type OnuRow } from '@/components/olt';
import { DataTable, Pagination } from '@/components/table';
import { Tabs } from '@/components/tabs';
import { Badge, Field, ListPanel, Panel, Select, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import type { Paginated } from '@/lib/types';

type Olt = { id: number; name: string; model: string; host: string; enabled: boolean; telnet: boolean; lastSync: string | null; lastMacSync: string | null; syncRequested: boolean; error: string; macError: string };
type Counts = { total: number; online: number; offline: number; weak: number };

type History = { range: string; ranges: Record<string, string>; charts: { key: string; title: string; url: string; data: ChartPayload }[] };
type Board = { position: string; name: string; status: string; tone: string; role: string; power: string; serial: string; cpu: number | null; memory: number | null; temperature: number | null };
type Port = { id: number; name: string; alias: string; kind: string; admin: boolean | null; oper: boolean | null; speed: number | null; in: string | null; out: string | null; errors: string | null; onus: { total: number; online: number } | null };
type Fan = { label: string; state: string; ok: boolean; speed: number | null };
type Hardware = { lastRead: string | null; error: string; boards: Board[]; fans: Fan[]; ports: Port[] };

const speedText = (mbps: number | null) => (mbps === null || mbps === 0 ? '-' : mbps >= 1000 ? `${mbps / 1000} Gbit/s` : `${mbps} Mbit/s`);

function PortState({ port }: { port: Port }) {
    if (port.admin === false) return <Badge>Turned off</Badge>;
    if (port.oper === true) return <Badge tone="success">Up</Badge>;
    if (port.oper === false) return <Badge tone={port.kind === 'uplink' ? 'danger' : 'warning'}>Down</Badge>;
    return <Badge>Unknown</Badge>;
}

function HardwareNote({ hardware }: { hardware: Hardware }) {
    return (
        <>
            {hardware.error && <p className="flash warning" role="alert">The last read failed, so this may be out of date: {hardware.error}</p>}
            <p className="hint">Read <Time value={hardware.lastRead} /> (every minute). A dash means the OLT did not report that value.</p>
        </>
    );
}

function Ports({ hardware }: { hardware: Hardware }) {
    const table = (title: string, rows: Port[], pon: boolean) => (
        <Panel title={title}>
            <DataTable
                rows={rows}
                rowKey={(p) => p.id}
                exportRows
                emptyText="The OLT did not report any of these ports."
                columns={[
                    { header: 'Port', cell: (p) => <span className="mono">{p.name}</span> },
                    { header: 'State', cell: (p) => <PortState port={p} /> },
                    ...(pon ? [{ header: 'ONUs online', cell: (p: Port) => (p.onus ? `${p.onus.online} / ${p.onus.total}` : '-') }] : []),
                    { header: 'Speed', cell: (p) => speedText(p.speed) },
                    { header: 'In', cell: (p) => p.in ?? '-' },
                    { header: 'Out', cell: (p) => p.out ?? '-' },
                    { header: 'Errors in total', cell: (p) => p.errors ?? '-' },
                    { header: 'Description', className: 'wrap', cell: (p) => p.alias || '-' },
                ]}
            />
        </Panel>
    );
    return (
        <>
            <HardwareNote hardware={hardware} />
            {table('Uplink ports', hardware.ports.filter((p) => p.kind === 'uplink'), false)}
            {table('PON ports', hardware.ports.filter((p) => p.kind === 'pon'), true)}
        </>
    );
}

function Cards({ hardware }: { hardware: Hardware }) {
    const value = (v: number | null, unit: string) => (v === null ? '-' : `${v} ${unit}`);
    return (
        <>
            <HardwareNote hardware={hardware} />
            <Panel title="Cards">
                <DataTable
                    rows={hardware.boards}
                    rowKey={(b) => b.position}
                    exportRows
                    emptyText="The OLT did not report any cards. Check that its SNMP view includes them."
                    columns={[
                        { header: 'Slot', cell: (b) => <span className="mono">{b.position}</span> },
                        { header: 'Card', cell: (b) => b.name },
                        { header: 'Status', cell: (b) => <Badge tone={b.tone}>{b.status}</Badge> },
                        { header: 'Role', cell: (b) => b.role || '-' },
                        { header: 'CPU', cell: (b) => value(b.cpu, '%') },
                        { header: 'Memory', cell: (b) => value(b.memory, '%') },
                        { header: 'Temperature', cell: (b) => value(b.temperature, '°C') },
                        { header: 'Power input', cell: (b) => (b.power === 'Fault' ? <Badge tone="danger">Fault</Badge> : b.power || '-') },
                        { header: 'Serial', cell: (b) => (b.serial ? <span className="mono">{b.serial}</span> : '-') },
                    ]}
                />
            </Panel>
            <Panel title="Fans">
                {hardware.fans.length ? (
                    <DataTable
                        rows={hardware.fans}
                        rowKey={(f) => f.label}
                        columns={[
                            { header: 'Fan', cell: (f) => f.label },
                            { header: 'State', cell: (f) => <Badge tone={f.ok ? 'success' : 'danger'}>{f.state}</Badge> },
                            { header: 'Speed', cell: (f) => value(f.speed, '%') },
                        ]}
                    />
                ) : <p className="hint">This OLT doesn&apos;t report its fans over SNMP. The card temperatures above still show if cooling is failing.</p>}
            </Panel>
        </>
    );
}

export default function OltShow({ olt, counts, filters, hardware, history, result }: { olt: Olt; counts: Counts; filters: { q: string; status: string }; hardware: Hardware; history: History; result: Paginated<OnuRow> }) {
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
            <Tabs
                label="OLT sections"
                className="tabs"
                tabs={[
                    {
                        key: 'onus', title: 'ONUs', icon: 'fiber', count: counts.total,
                        content: (
                            <>
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
                            </>
                        ),
                    },
                    { key: 'ports', title: 'Ports', icon: 'signal', count: hardware.ports.length, content: <Ports hardware={hardware} /> },
                    { key: 'cards', title: 'Cards', icon: 'server', count: hardware.boards.length, content: <Cards hardware={hardware} /> },
                    {
                        key: 'graphs', title: 'Graphs', icon: 'reports',
                        content: (
                            <>
                                <div className="toolbar traffic-filters">
                                    <nav className="segmented" aria-label="Time range">
                                        {Object.entries(history.ranges).map(([key, label]) => (
                                            <Link key={key} href={`${path}?tab=graphs&range=${key}`} preserveScroll aria-current={key === history.range ? 'page' : undefined}>{label}</Link>
                                        ))}
                                    </nav>
                                </div>
                                {history.charts.map((c) => <LivePanel key={c.url} title={c.title} url={c.url} interval={60} initial={c.data} className="traffic-panel" render={(payload) => <ChartOrMessage payload={payload} />} />)}
                            </>
                        ),
                    },
                ]}
            />
        </AppLayout>
    );
}
