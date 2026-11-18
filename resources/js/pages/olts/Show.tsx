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

type Olt = { id: number; name: string; model: string; host: string; enabled: boolean; telnet: boolean; canChange: boolean; lastSync: string | null; lastMacSync: string | null; syncRequested: boolean; error: string; macError: string };
type Counts = { total: number; online: number; offline: number; weak: number };

type Graphs = { range: string; ranges: Record<string, string>; charts: { key: string; title: string; url: string; data: ChartPayload }[] };
type Board = { position: string; name: string; status: string; tone: string; role: string; power: string; serial: string; cpu: number | null; memory: number | null; temperature: number | null; watts: number | null };
type Port = { id: number; name: string; alias: string; kind: string; admin: boolean | null; oper: boolean | null; speed: number | null; in: string | null; out: string | null; errors: string | null; onus: { total: number; online: number } | null; signal: number | null;
    pon: { tx: number | null; temperature: number | null; autofind: boolean | null; min: number | null; max: number | null; wavelength: number | null; rogue: string } | null;
    uplink: { type: string; optic: string; duplex: string; native: number | null; vlans: number[] } | null;
};
type Fan = { label: string; state: string; ok: boolean; speed: number | null; rpm: number | null };
type Profile = { id: number; name: string; bound: number };
type Details = {
    description: string; version: { software: string; patch: string; product: string } | null; bootedAt: string | null; power: number | null; temperature: number | null;
    snmp: string; telnetPort: number | null; ntp: { server: string; status: string; reach: number }[]; autosave: { on: boolean | null; minutes: number | null } | null;
    vlans: { id: number; ports: number; type: string; standard: number }[]; lineProfiles: Profile[]; serviceProfiles: Profile[];
    dbaProfiles: { id: number; type: number; fix: number; assure: number; max: number; bound: number }[]; trafficTables: { id: number; cir: string; pir: string }[];
    read: string | null; error: string; backupAt: string | null; backupError: string;
};
type Backup = { id: number; taken: string; checked: string; bytes: number };
type HistoryRow = { at: string; who: string; action: string; what: string; detail: string };

const kbps = (v: number | string) => (v === 'off' ? 'No limit' : Number(v) >= 1000 ? `${Math.round(Number(v) / 100) / 10} Mbit/s` : `${v} kbit/s`);
const ACTIONS: Record<string, string> = {
    create: 'Added to the portal', update: 'Settings changed', backup: 'Configuration copied', backup_download: 'Configuration downloaded',
    onu_add: 'ONU added', onu_delete: 'ONU removed', onu_reboot: 'ONU restarted', onu_disable: 'ONU turned off', onu_enable: 'ONU turned on', onu_describe: 'ONU renamed',
};
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
                    ...(pon ? [
                        { header: 'ONUs online', cell: (p: Port) => (p.onus ? `${p.onus.online} / ${p.onus.total}` : '-') },
                        { header: 'Autofind', cell: (p: Port) => (p.pon?.autofind == null ? '-' : p.pon.autofind ? 'On' : <Badge tone="warning">Off</Badge>) },
                        { header: 'Average signal', cell: (p: Port) => <OnuPower dbm={p.signal} /> },
                        { header: 'Transmit power', cell: (p: Port) => (p.pon?.tx == null ? '-' : `${p.pon.tx.toFixed(2)} dBm`) },
                        { header: 'Module', cell: (p: Port) => (p.pon?.temperature == null ? '-' : `${p.pon.temperature} °C`) },
                        { header: 'Range', cell: (p: Port) => (p.pon?.max == null ? '-' : `${p.pon.min ?? 0} to ${p.pon.max} km`) },
                    ] : [
                        { header: 'Type', cell: (p: Port) => (p.uplink ? `${p.uplink.type}${p.uplink.optic === 'absence' ? ', no module' : ''}` : '-') },
                        { header: 'VLANs', className: 'wrap', cell: (p: Port) => (p.uplink ? <>{p.uplink.vlans.join(', ') || '-'}{p.uplink.native !== null && <span className="hint"> (native {p.uplink.native})</span>}</> : '-') },
                    ]),
                    { header: 'Speed', cell: (p) => speedText(p.speed) },
                    { header: 'In', cell: (p) => p.in ?? '-' },
                    { header: 'Out', cell: (p) => p.out ?? '-' },
                    { header: 'Errors in total', cell: (p) => p.errors ?? '-' },
                    { header: 'Description', className: 'wrap', cell: (p) => (p.pon?.rogue && p.pon.rogue.toLowerCase() !== 'inexistent' ? <><Badge tone="danger">Rogue ONU: {p.pon.rogue}</Badge> {p.alias}</> : p.alias || '-') },
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
                        { header: 'Power use', cell: (b) => value(b.watts, 'W') },
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
                            { header: 'Speed', cell: (f) => (f.rpm !== null ? `${f.rpm} RPM` : value(f.speed, '%')) },
                        ]}
                    />
                ) : <p className="hint">This OLT doesn&apos;t report its fans over SNMP. The card temperatures above still show if cooling is failing.</p>}
            </Panel>
        </>
    );
}

function uptime(bootedAt: string | null): string {
    if (!bootedAt) return '-';
    const seconds = Math.max(0, Math.round((Date.now() - new Date(bootedAt.replace(' ', 'T')).getTime()) / 1000));
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    return days > 0 ? `${days} d ${hours} h` : `${hours} h ${Math.floor((seconds % 3600) / 60)} min`;
}

function DetailsTab({ olt, details, hardware }: { olt: Olt; details: Details; hardware: Hardware }) {
    const row = (label: string, value: React.ReactNode) => <div className="kv"><span>{label}</span><strong>{value}</strong></div>;
    const synced = details.ntp.some((n) => n.reach > 0 && !/unsync/i.test(n.status));
    return (
        <>
            {details.error && <p className="flash warning" role="alert">The last read over telnet failed, so some of this may be out of date: {details.error}</p>}
            <Panel title="OLT">
                {row('Name', olt.name)}
                {row('Model', details.version?.product || olt.model)}
                {row('Software', details.version?.software ? `${details.version.software}${details.version.patch ? `, patch ${details.version.patch}` : ''}` : details.description || '-')}
                {row('Management address', <span className="mono">{olt.host}</span>)}
                {row('SNMP', details.snmp)}
                {row('Telnet', details.telnetPort === null ? 'Off' : `TCP ${details.telnetPort}`)}
                {row('Changes from the portal', olt.canChange ? 'Allowed' : 'Off: the portal only reads')}
                {row('Running for', <>{uptime(details.bootedAt)}{details.bootedAt && <span className="hint"> (since <Time value={details.bootedAt} />)</span>}</>)}
                {row('Power use', details.power === null ? '-' : `${details.power} W`)}
                {row('Hottest card', details.temperature === null ? '-' : `${details.temperature} °C`)}
                {row('Fans', hardware.fans.length ? hardware.fans.map((f) => (f.rpm !== null ? `${f.rpm}` : f.state)).join(', ') + (hardware.fans.some((f) => f.rpm !== null) ? ' RPM' : '') : '-')}
                {row('Saves its configuration by itself', details.autosave?.on == null ? '-' : details.autosave.on ? `Yes, ${details.autosave.minutes ?? '?'} minutes after a change` : <Badge tone="warning">No: changes are lost at a restart unless saved on the OLT</Badge>)}
                {row('Time servers (NTP)', details.ntp.length ? <>{details.ntp.map((n) => n.server).join(', ')} {synced ? <Badge tone="success">In sync</Badge> : <Badge tone="warning">Not in sync: the OLT cannot reach them</Badge>}</> : '-')}
            </Panel>
            <p className="hint">Power, uptime and fans are read every minute over SNMP. Software, time servers and saving are read every 15 minutes over telnet{details.read ? <>, last <Time value={details.read} /></> : ''}.</p>
        </>
    );
}

function VlansTab({ details }: { details: Details }) {
    const profiles = (title: string, rows: Profile[]) => (
        <Panel title={title}>
            <DataTable rows={rows} rowKey={(p) => p.id} emptyText="Not read yet." columns={[
                { header: 'ID', cell: (p) => p.id },
                { header: 'Name', cell: (p) => p.name },
                { header: 'ONUs using it', cell: (p) => p.bound },
            ]} />
        </Panel>
    );
    return (
        <>
            <p className="hint">As the OLT has them, read every 15 minutes over telnet{details.read ? <>, last <Time value={details.read} /></> : ''}. They are created on the OLT; the portal uses them when you add an ONU.</p>
            <Panel title="VLANs">
                <DataTable rows={details.vlans} rowKey={(v) => v.id} emptyText="Not read yet. VLANs show once telnet is on and the OLT has been read." columns={[
                    { header: 'VLAN', cell: (v) => v.id },
                    { header: 'Type', cell: (v) => v.type },
                    { header: 'Uplink ports', cell: (v) => v.standard },
                    { header: 'Service ports (ONUs)', cell: (v) => v.ports },
                ]} />
            </Panel>
            {profiles('Line profiles', details.lineProfiles)}
            {profiles('Service profiles', details.serviceProfiles)}
            <Panel title="Traffic tables">
                <DataTable rows={details.trafficTables} rowKey={(t) => t.id} emptyText="Not read yet." columns={[
                    { header: 'ID', cell: (t) => t.id },
                    { header: 'Guaranteed', cell: (t) => kbps(t.cir) },
                    { header: 'Maximum', cell: (t) => kbps(t.pir) },
                ]} />
            </Panel>
            <Panel title="DBA profiles (upstream)">
                <DataTable rows={details.dbaProfiles} rowKey={(d) => d.id} emptyText="Not read yet." columns={[
                    { header: 'ID', cell: (d) => d.id },
                    { header: 'Type', cell: (d) => d.type },
                    { header: 'Fixed', cell: (d) => (d.fix ? kbps(d.fix) : '-') },
                    { header: 'Assured', cell: (d) => (d.assure ? kbps(d.assure) : '-') },
                    { header: 'Maximum', cell: (d) => (d.max ? kbps(d.max) : '-') },
                    { header: 'In use', cell: (d) => d.bound },
                ]} />
            </Panel>
        </>
    );
}

function BackupsTab({ olt, details, backups, path }: { olt: Olt; details: Details; backups: Backup[]; path: string }) {
    return (
        <>
            {details.backupError && <p className="flash warning" role="alert">The last copy failed: {details.backupError}</p>}
            <Panel title="Configuration copies" actions={olt.telnet ? <PostButton action={`${path}/backup`} label="Copy now" className="button" /> : undefined}>
                <p className="hint">The portal reads the OLT&apos;s whole configuration once a day and keeps a copy each time it changed, up to 30. A copy holds the OLT&apos;s own passwords, so it is stored sealed and each download is written to the audit log. To restore, paste the part you need on the OLT&apos;s command line.</p>
                <DataTable rows={backups} rowKey={(b) => b.id} emptyText={olt.telnet ? 'No copy yet. The first one is taken within 15 minutes, or press Copy now.' : 'Needs telnet turned on for this OLT.'} columns={[
                    { header: 'Configuration from', cell: (b) => <Time value={b.taken} /> },
                    { header: 'Still the same at', cell: (b) => <Time value={b.checked} /> },
                    { header: 'Size', cell: (b) => `${Math.round(b.bytes / 102.4) / 10} KB` },
                    { header: '', cell: (b) => <a className="button" href={`${path}/backups/${b.id}`} download>Download</a> },
                ]} />
            </Panel>
        </>
    );
}

function HistoryTab({ rows }: { rows: HistoryRow[] }) {
    return (
        <Panel title="What was done on this OLT from the portal">
            <DataTable rows={rows} rowKey={(r) => r.at + r.action + r.what} exportRows emptyText="Nothing yet." columns={[
                { header: 'When', cell: (r) => <Time value={r.at} /> },
                { header: 'Who', cell: (r) => r.who },
                { header: 'What', cell: (r) => `${ACTIONS[r.action] ?? r.action}${r.what !== 'OLT' ? `: ${r.what}` : ''}` },
                { header: 'Commands sent to the OLT', className: 'wrap', cell: (r) => (r.detail ? <span className="mono">{r.detail}</span> : '-') },
            ]} />
        </Panel>
    );
}

export default function OltShow({ olt, counts, filters, hardware, details, backups, history, graphs, result }: { olt: Olt; counts: Counts; filters: { q: string; status: string }; hardware: Hardware; details: Details; backups: Backup[] | null; history: HistoryRow[]; graphs: Graphs; result: Paginated<OnuRow> }) {
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
                {can('nas.manage') && olt.telnet && <Link className="button" href={`${path}/provision`}>New ONUs</Link>}
                {olt.telnet && <a className="button" href={`${path}/macs.csv`} download>Export MAC table</a>}
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
                {olt.canChange ? ' Changes from the portal are allowed: open an ONU to restart, turn off, rename or remove it.' : ' The portal only reads this OLT.'}
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
                        { header: 'Serial', cell: (o) => <Link className="mono" href={`${path}/onus/${o.id}`}>{o.sn}</Link> },
                        { header: 'Description', className: 'wrap', cell: (o) => o.description || '-' },
                        { header: 'Client', cell: (o) => (o.clients.length ? o.clients.map((u) => <div key={u}><Link href={`/clients/${encodeURIComponent(u)}/edit?tab=onu`}>{u}</Link></div>) : '-') },
                        { header: 'Status', cell: (o) => (o.active === false ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={o.status} />) },
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
                    { key: 'details', title: 'OLT details', icon: 'server', content: <DetailsTab olt={olt} details={details} hardware={hardware} /> },
                    { key: 'ports', title: 'Ports', icon: 'signal', count: hardware.ports.length, content: <Ports hardware={hardware} /> },
                    { key: 'cards', title: 'Cards', icon: 'server', count: hardware.boards.length, content: <Cards hardware={hardware} /> },
                    {
                        key: 'graphs', title: 'Graphs', icon: 'reports',
                        content: (
                            <>
                                <div className="toolbar traffic-filters">
                                    <nav className="segmented" aria-label="Time range">
                                        {Object.entries(graphs.ranges).map(([key, label]) => (
                                            <Link key={key} href={`${path}?tab=graphs&range=${key}`} preserveScroll aria-current={key === graphs.range ? 'page' : undefined}>{label}</Link>
                                        ))}
                                    </nav>
                                </div>
                                {graphs.charts.map((c) => <LivePanel key={c.url} title={c.title} url={c.url} interval={60} initial={c.data} className="traffic-panel" render={(payload) => <ChartOrMessage payload={payload} />} />)}
                            </>
                        ),
                    },
                    { key: 'vlans', title: 'VLANs and profiles', icon: 'services', count: details.vlans.length, content: <VlansTab details={details} /> },
                    ...(backups ? [{ key: 'backups', title: 'Config backups', icon: 'book', count: backups.length, content: <BackupsTab olt={olt} details={details} backups={backups} path={path} /> }] : []),
                    { key: 'history', title: 'History', icon: 'logs', count: history.length, content: <HistoryTab rows={history} /> },
                ]}
            />
        </AppLayout>
    );
}
