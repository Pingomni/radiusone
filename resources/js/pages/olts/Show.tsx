import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { ChartOrMessage, type ChartPayload } from '@/components/LineChart';
import { LivePanel } from '@/components/LivePanel';
import OltConsole from '@/components/OltConsole';
import { OnuPower, OnuStatus, type OnuRow } from '@/components/olt';
import { DataTable, Pagination } from '@/components/table';
import { Tabs } from '@/components/tabs';
import { Badge, Field, ListPanel, Panel, RowActions, Select, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import type { Paginated } from '@/lib/types';

type Olt = { id: number; name: string; model: string; host: string; enabled: boolean; telnet: boolean; canChange: boolean; rxWarning: number; rxCritical: number; lastSync: string | null; lastMacSync: string | null; syncRequested: boolean; error: string; macError: string };
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
    cli: 'Display command run', card_restart: 'Card restarted', port_autofind_on: 'Autofind turned on', port_autofind_off: 'Autofind turned off', port_restart_onus: 'All ONUs on a port restarted', port_off: 'PON port turned off', port_on: 'PON port turned on',
    ntp_add: 'Time server added', ntp_remove: 'Time server removed', vlan_add: 'VLAN created', vlan_remove: 'VLAN deleted', uplink_vlan_add: 'VLAN added to an uplink', uplink_vlan_remove: 'VLAN taken off an uplink',
    onu_add: 'ONU added', onu_delete: 'ONU removed', onu_reboot: 'ONU restarted', onu_disable: 'ONU turned off', onu_enable: 'ONU turned on', onu_describe: 'ONU renamed',
    onu_wan: 'ONU internet connection set', onu_bridge: 'ONU switched to bridging', onu_eth_port: 'ONU Ethernet port set', onu_lan_vlan: 'ONU LAN VLAN set', onu_speed: 'ONU speed changed',
    onu_lan_on: 'ONU LAN port turned on', onu_lan_off: 'ONU LAN port turned off', onu_factory_reset: 'ONU reset to factory settings', olt_traffic_table_add: 'Speed added', olt_terminal: 'Terminal command',
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

/** One port's traffic. Fetched when opened, as the page does not carry a graph for every port. */
function PortGraph({ title, url }: { title: string; url: string }) {
    const [payload, setPayload] = useState<ChartPayload | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        let alive = true;
        fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((data) => { if (alive) setPayload(data as ChartPayload); })
            .catch(() => { if (alive) setFailed(true); });
        return () => { alive = false; };
    }, [url]);
    if (failed) return <Panel title={title}><p className="flash warning" role="alert">The graph could not be loaded. Reload the page and try again.</p></Panel>;
    if (!payload) return <Panel title={title}><p className="hint" role="status">Loading the graph.</p></Panel>;
    return <LivePanel title={title} url={url} interval={60} initial={payload} className="traffic-panel" render={(data) => <ChartOrMessage payload={data} />} />;
}

function Ports({ hardware, olt, path, range }: { hardware: Hardware; olt: Olt; path: string; range: string }) {
    const can = useCan();
    const [shown, setShown] = useState<Port | null>(null);
    const mayChange = olt.canChange && can('nas.manage');
    const actions = (p: Port) => (
        <RowActions label={`Actions for ${p.name}`}>
            <button type="button" className="link" onClick={() => setShown(shown?.id === p.id ? null : p)}>{shown?.id === p.id ? 'Hide traffic graph' : 'Traffic graph'}</button>
            {mayChange && p.kind === 'pon' && (
                <>
                    {p.pon?.autofind === false
                        ? <PostButton action={`${path}/ports/${p.id}/autofind-on`} label="Turn autofind on" className="link" />
                        : <PostButton action={`${path}/ports/${p.id}/autofind-off`} label="Turn autofind off" className="link" confirm={{ title: `Turn autofind off on ${p.name}?`, message: 'New ONUs plugged in on this port will no longer show under New ONUs. ONUs already added keep working.', confirmLabel: 'Turn off' }} />}
                    <PostButton action={`${path}/ports/${p.id}/restart-onus`} label="Restart all ONUs" confirm={{ title: `Restart every ONU on ${p.name}?`, message: `${p.onus?.online ?? 0} customers on this port lose their connection for a minute or two.`, confirmLabel: 'Restart all', typeToConfirm: p.name }} />
                    {can('danger.delete') && (p.admin === false
                        ? <PostButton action={`${path}/ports/${p.id}/on`} label="Turn port on" className="link" />
                        : <PostButton action={`${path}/ports/${p.id}/off`} label="Turn port off" confirm={{ title: `Turn ${p.name} off?`, message: `Every ONU on this port (${p.onus?.total ?? 0}) is offline until you turn the port on again.`, confirmLabel: 'Turn port off', typeToConfirm: p.name }} />)}
                </>
            )}
        </RowActions>
    );
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
                        { header: 'Average signal', cell: (p: Port) => <OnuPower dbm={p.signal} weak={olt.rxWarning} veryWeak={olt.rxCritical} /> },
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
                    { header: '', cell: (p) => actions(p) },
                    { header: 'Description', className: 'wrap', cell: (p) => (p.pon?.rogue && p.pon.rogue.toLowerCase() !== 'inexistent' ? <><Badge tone="danger">Rogue ONU: {p.pon.rogue}</Badge> {p.alias}</> : p.alias || '-') },
                ]}
            />
        </Panel>
    );
    return (
        <>
            <HardwareNote hardware={hardware} />
            {shown && <PortGraph key={shown.id} title={`Traffic on ${shown.name}`} url={`${path}/graph?chart=port&port=${shown.id}&range=${range}`} />}
            {table('Uplink ports', hardware.ports.filter((p) => p.kind === 'uplink'), false)}
            {table('PON ports', hardware.ports.filter((p) => p.kind === 'pon'), true)}
        </>
    );
}

function Cards({ hardware, olt, path }: { hardware: Hardware; olt: Olt; path: string }) {
    const can = useCan();
    const mayRestart = olt.canChange && can('nas.manage') && can('danger.delete');
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
                        ...(mayRestart ? [{ header: '', cell: (b: Board) => (
                            <PostButton action={`${path}/cards/${b.position}/restart`} label="Restart card"
                                confirm={{ title: `Restart ${b.name} in slot ${b.position}?`, message: b.role ? 'This is a control card. Restarting the active one moves the OLT to the standby card, or restarts the whole OLT if there is none.' : 'Every port on this card, and every customer behind it, is offline for a few minutes.', confirmLabel: 'Restart card', typeToConfirm: b.name }} />
                        ) }] : []),
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

function DetailsTab({ olt, details, hardware, path }: { olt: Olt; details: Details; hardware: Hardware; path: string }) {
    const can = useCan();
    const [ntp, setNtp] = useState('');
    const mayChange = olt.canChange && can('nas.manage');
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
            {mayChange && (
                <Panel title="Time servers">
                    <p className="hint">The OLT stamps its logs and ONU events with its own clock. A server it can reach keeps that clock right.</p>
                    {details.ntp.map((n) => (
                        <div className="kv" key={n.server}>
                            <span className="mono">{n.server} <span className="hint">{n.reach > 0 ? 'reachable' : 'not reachable'}</span></span>
                            {can('danger.delete') && <PostButton action={`${path}/settings/ntp`} data={{ action: 'remove', server: n.server }} label="Remove" confirm={{ title: `Remove time server ${n.server}?`, message: 'The OLT stops asking this server for the time.', confirmLabel: 'Remove' }} />}
                        </div>
                    ))}
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${path}/settings/ntp`, { action: 'add', server: ntp }, { preserveScroll: true, onSuccess: () => setNtp('') }); }}>
                        <Field label="Add a time server" name="server" placeholder="IPv4 address" inputMode="decimal" autoComplete="off" value={ntp} onChange={(e) => setNtp(e.target.value)} />
                        <button className="button" disabled={!ntp.trim()}>Add</button>
                    </form>
                </Panel>
            )}
            <p className="hint">Power, uptime and fans are read every minute over SNMP. Software, time servers and saving are read every 15 minutes over telnet{details.read ? <>, last <Time value={details.read} /></> : ''}.</p>
        </>
    );
}

function VlansTab({ details, olt, hardware, path }: { details: Details; olt: Olt; hardware: Hardware; path: string }) {
    const can = useCan();
    const [vlan, setVlan] = useState('');
    const [uplink, setUplink] = useState({ port: '', vlan: '' });
    const mayChange = olt.canChange && can('nas.manage');
    const mayRemove = mayChange && can('danger.delete');
    const uplinks = hardware.ports.filter((p) => p.kind === 'uplink' && p.uplink);
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
                    ...(mayRemove ? [{ header: '', cell: (v: Details['vlans'][number]) => (v.id > 1 && v.ports === 0 ? <PostButton action={`${path}/settings/vlans`} data={{ action: 'remove', vlan: v.id }} label="Delete" confirm={{ title: `Delete VLAN ${v.id} on the OLT?`, message: 'The OLT refuses if the VLAN is still used, and the portal refuses if the OLT has its own address on it.', confirmLabel: 'Delete VLAN', typeToConfirm: String(v.id) }} /> : '') }] : []),
                ]} />
                {mayChange && (
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${path}/settings/vlans`, { action: 'add', vlan }, { preserveScroll: true, onSuccess: () => setVlan('') }); }}>
                        <Field label="Create a VLAN" name="vlan" type="number" min={2} max={4093} value={vlan} onChange={(e) => setVlan(e.target.value)} />
                        <button className="button" disabled={!vlan}>Create</button>
                    </form>
                )}
            </Panel>
            <Panel title="VLANs on uplink ports">
                <DataTable rows={uplinks} rowKey={(p) => p.id} emptyText="Not read yet." columns={[
                    { header: 'Port', cell: (p) => <span className="mono">{p.name}</span> },
                    { header: 'Description', cell: (p) => p.alias || '-' },
                    { header: 'VLANs it carries', className: 'wrap', cell: (p) => (p.uplink?.vlans.length ? p.uplink.vlans.map((v) => (
                        <span key={v} className="mono" style={{ marginRight: '0.75rem' }}>
                            {v}{mayRemove && v !== p.uplink?.native && <> <PostButton action={`${path}/settings/uplink-vlans`} data={{ action: 'remove', port: p.id, vlan: v }} label="remove" confirm={{ title: `Take VLAN ${v} off ${p.name}?`, message: `ONUs on VLAN ${v} lose their way out through this port.`, confirmLabel: 'Take off', typeToConfirm: String(v) }} /></>}
                        </span>
                    )) : '-') },
                ]} />
                {mayChange && uplinks.length > 0 && (
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${path}/settings/uplink-vlans`, { action: 'add', ...uplink }, { preserveScroll: true, onSuccess: () => setUplink({ port: '', vlan: '' }) }); }}>
                        <Select label="Add a VLAN to" name="port" placeholder="Choose a port" options={uplinks.map((p) => ({ value: String(p.id), label: p.alias ? `${p.name} (${p.alias})` : p.name }))} value={uplink.port} onChange={(e) => setUplink({ ...uplink, port: e.target.value })} />
                        <Select label="VLAN" name="vlan" placeholder="Choose" options={details.vlans.map((v) => ({ value: String(v.id), label: String(v.id) }))} value={uplink.vlan} onChange={(e) => setUplink({ ...uplink, vlan: e.target.value })} />
                        <button className="button" disabled={!uplink.port || !uplink.vlan}>Add</button>
                    </form>
                )}
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

function CommandLine({ path, full }: { path: string; full: 'role' | 'olt' | null }) {
    const [command, setCommand] = useState('display ');
    const [answer, setAnswer] = useState<{ ok: boolean; output?: string; error?: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const run = async () => {
        setBusy(true);
        try {
            const response = await fetch(`${path}/cli?command=${encodeURIComponent(command)}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            setAnswer(response.ok ? await response.json() : { ok: false, error: `The portal answered ${response.status}. Reload the page and try again.` });
        } catch {
            setAnswer({ ok: false, error: 'The portal could not be reached.' });
        } finally {
            setBusy(false);
        }
    };
    return (
        <Panel title="Command line (read only)">
            <p className="hint">Runs one <span className="mono">display</span> command on the OLT and shows its answer, for example <span className="mono">display board 0</span> or <span className="mono">display ont info 0 1 15 0</span>. Nothing but display commands is sent, so this cannot change the OLT. Each command is written to the audit log.{full === 'olt' ? ' The full terminal needs Allow changes from the portal, under Edit.' : full === 'role' ? ' The full terminal is for operators who may delete.' : ''}</p>
            <form className="toolbar" onSubmit={(e) => { e.preventDefault(); void run(); }}>
                <Field label="Command" name="command" className="grow" autoComplete="off" spellCheck={false} value={command} onChange={(e) => setCommand(e.target.value)} />
                <button className="button primary" disabled={busy || command.trim().length < 9}>{busy ? 'Asking the OLT…' : 'Run'}</button>
            </form>
            {answer && !answer.ok && <p className="flash warning" role="alert">{answer.error}</p>}
            {answer?.ok && <pre className="mono" style={{ overflowX: 'auto', whiteSpace: 'pre', maxHeight: '32rem' }} tabIndex={0} aria-label="The OLT's answer">{answer.output}</pre>}
        </Panel>
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

/** Where a port sits, from its name: "GPON 0/1/15" and "ethernet0/9/0" are frame/slot/port. */
const portAt = (name: string) => {
    const m = name.match(/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/);
    return m ? { slot: `${m[1]}/${m[2]}`, port: Number(m[3]) } : null;
};

type Light = 'up' | 'partial' | 'down' | 'idle' | 'off' | 'nomodule' | 'unknown';
/** An uplink port with no optical module fitted is unused, not down. */
const fitted = (p: Port) => p.kind !== 'uplink' || p.uplink?.optic !== 'absence';
/** A port's light: what an operator looks for first. A PON port with customers is judged by how many are online. */
function portLight(p: Port): Light {
    if (p.admin === false) return 'off';
    if (!fitted(p)) return 'nomodule';
    if (p.oper === null) return 'unknown';
    if (p.kind === 'pon') {
        const n = p.onus ?? { total: 0, online: 0 };
        if (!n.total) return p.oper ? 'idle' : 'idle';
        if (!p.oper || n.online === 0) return 'down';
        return n.online < n.total ? 'partial' : 'up';
    }
    return p.oper ? 'up' : 'down';
}
const LIGHT_WORDS: Record<Light, string> = { up: 'up', partial: 'some ONUs offline', down: 'down', idle: 'up, no ONUs', off: 'turned off', nomodule: 'no module', unknown: 'not reported' };

/** What needs a look on this OLT, worst first. */
function health(olt: Olt, hardware: Hardware, details: Details): { tone: 'success' | 'warning' | 'danger'; reasons: string[] } {
    const danger: string[] = [];
    const warning: string[] = [];
    if (olt.error) danger.push('SNMP read failed');
    const uplinksDown = hardware.ports.filter((p) => p.kind === 'uplink' && fitted(p) && p.admin !== false && p.oper === false);
    if (uplinksDown.length) danger.push(`${uplinksDown.length === 1 ? `Uplink ${uplinksDown[0].name} is` : `${uplinksDown.length} uplinks are`} down`);
    const badCards = hardware.boards.filter((b) => b.tone === 'danger' || b.power === 'Fault');
    if (badCards.length) danger.push(`${badCards.length === 1 ? `Card ${badCards[0].name} in slot ${badCards[0].position} needs` : `${badCards.length} cards need`} a look`);
    if (olt.macError || details.error) warning.push('Telnet read failed');
    if (hardware.fans.some((f) => !f.ok)) warning.push('A fan reports a fault');
    if (details.autosave?.on === false) warning.push('The OLT does not save its configuration by itself');
    const ponDown = hardware.ports.filter((p) => p.kind === 'pon' && portLight(p) === 'down');
    if (ponDown.length) warning.push(`${ponDown.length} PON port${ponDown.length === 1 ? ' has' : 's have'} all ONUs offline`);
    return danger.length ? { tone: 'danger', reasons: [...danger, ...warning] } : warning.length ? { tone: 'warning', reasons: warning } : { tone: 'success', reasons: [] };
}

/** The OLT as it stands in the rack: each card in its slot with its state, and a light for each of its ports. */
function Chassis({ hardware, model, path }: { hardware: Hardware; model: string; path: string }) {
    const bySlot = new Map<string, Port[]>();
    for (const p of hardware.ports) {
        const at = portAt(p.name);
        if (at) bySlot.set(at.slot, [...(bySlot.get(at.slot) ?? []), p].sort((a, b) => (portAt(a.name)?.port ?? 0) - (portAt(b.name)?.port ?? 0)));
    }
    const boards = [...hardware.boards].sort((a, b) => a.position.localeCompare(b.position, undefined, { numeric: true }));
    if (!boards.length) return <div className="olt-chassis olt-chassis-empty"><p>The OLT has not reported its cards yet.</p></div>;
    return (
        <figure className="olt-chassis" aria-label={`${model}: ${boards.length} cards`}>
            <div className="olt-chassis-frame">
                {boards.map((b) => {
                    const ports = bySlot.get(b.position) ?? [];
                    return (
                        <div key={b.position} className={`olt-card tone-${b.tone || 'none'}`}>
                            <span className="olt-card-slot mono">{b.position.split('/')[1] ?? b.position}</span>
                            <span className="olt-card-led" title={b.status} aria-hidden="true" />
                            <span className="olt-card-name"><strong>{b.name}</strong>{b.role ? <small>{b.role}</small> : null}</span>
                            <span className="olt-card-ports">
                                {ports.map((p) => {
                                    const light = portLight(p);
                                    const n = p.onus;
                                    return (
                                        <Link key={p.id} href={`${path}?tab=ports`} className={`olt-port ${light}`} title={`${p.name}: ${LIGHT_WORDS[light]}${n && n.total ? `, ${n.online} of ${n.total} ONUs online` : ''}${p.signal !== null ? `, average ${p.signal.toFixed(1)} dBm` : ''}`} aria-label={`${p.name}: ${LIGHT_WORDS[light]}`}>
                                            <span className="olt-port-num">{portAt(p.name)?.port}</span>
                                        </Link>
                                    );
                                })}
                            </span>
                            <span className="olt-card-meta">
                                {b.temperature !== null && <span>{b.temperature} °C</span>}
                                {b.cpu !== null && <span>CPU {b.cpu}%</span>}
                            </span>
                        </div>
                    );
                })}
            </div>
            <figcaption className="olt-chassis-legend">
                {(['up', 'partial', 'down', 'idle', 'off', 'nomodule'] as Light[]).map((l) => <span key={l}><i className={`olt-port ${l}`} />{LIGHT_WORDS[l][0].toUpperCase() + LIGHT_WORDS[l].slice(1)}</span>)}
            </figcaption>
        </figure>
    );
}

/** Every PON port with customers on it: how many are online, and how strong their signal is on average. */
function PonGrid({ hardware, olt, path }: { hardware: Hardware; olt: Olt; path: string }) {
    const used = hardware.ports.filter((p) => p.kind === 'pon' && (p.onus?.total ?? 0) > 0)
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
    const empty = hardware.ports.filter((p) => p.kind === 'pon').length - used.length;
    if (!used.length) return null;
    return (
        <section className="olt-pon" aria-label="PON ports in use">
            <div className="olt-pon-head">
                <h3>PON ports in use</h3>
                <span>{used.length} with ONUs{empty > 0 ? ` · ${empty} empty` : ''}</span>
            </div>
            <div className="olt-pon-grid">
                {used.map((p) => {
                    const n = p.onus ?? { total: 0, online: 0 };
                    const share = n.total ? Math.round((n.online / n.total) * 100) : 0;
                    const tone = p.signal === null ? '' : p.signal < olt.rxCritical ? 'danger' : p.signal < olt.rxWarning ? 'warning' : 'success';
                    return (
                        <Link key={p.id} href={`${path}?tab=ports`} className={`olt-pon-card ${portLight(p)}`}>
                            <span className="olt-pon-name mono">{p.name.replace(/^GPON\s*/i, '')}</span>
                            <span className="olt-pon-count"><strong>{n.online}</strong>/{n.total}<small> online</small></span>
                            <span className="olt-pon-bar" role="img" aria-label={`${share}% online`}><i style={{ width: `${share}%` }} /></span>
                            <span className="olt-pon-foot">
                                <span className={`olt-pon-signal ${tone}`}>{p.signal === null ? 'No signal reading' : `${p.signal.toFixed(1)} dBm avg`}</span>
                                {p.pon?.autofind === false && <Badge tone="warning">Autofind off</Badge>}
                                {p.pon?.rogue && p.pon.rogue.toLowerCase() !== 'inexistent' && <Badge tone="danger">Rogue ONU</Badge>}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}

function OltHero({ olt, counts, hardware, details, path }: { olt: Olt; counts: Counts; hardware: Hardware; details: Details; path: string }) {
    const can = useCan();
    const h = health(olt, hardware, details);
    // Only uplinks with a module fitted: the empty cages are spare.
    const uplinks = hardware.ports.filter((p) => p.kind === 'uplink' && fitted(p) && p.admin !== false);
    const up = uplinks.filter((p) => p.oper === true).length;
    const online = counts.total ? Math.round((counts.online / counts.total) * 1000) / 10 : 0;
    const software = details.version?.software ? `${details.version.software}${details.version.patch ? ` ${details.version.patch}` : ''}` : '';
    return (
        <section className={`onu-hero olt-hero is-${h.tone}`} aria-label="OLT at a glance">
            <div className="onu-hero-top">
                <div className="onu-identity">
                    <div className="onu-title"><h2>{olt.name}</h2>{!olt.enabled && <Badge>Not read</Badge>}</div>
                    <p>
                        <span>{details.version?.product || olt.model}</span>
                        <span aria-hidden="true">·</span><span className="mono">{olt.host}</span>
                        {software && <><span aria-hidden="true">·</span><span className="mono">{software}</span></>}
                    </p>
                    <div className="onu-status-line">
                        <Badge tone={h.tone}>{h.tone === 'success' ? 'Healthy' : h.tone === 'warning' ? 'Needs a look' : 'Problem'}</Badge>
                        {details.bootedAt && <span>up <strong>{uptime(details.bootedAt)}</strong></span>}
                        {details.temperature !== null && <span><strong>{details.temperature} °C</strong> hottest card</span>}
                        {details.power !== null && <span><strong>{details.power} W</strong></span>}
                        <span className="onu-pending">{olt.canChange ? 'Changes allowed' : 'Read only'}</span>
                    </div>
                    {h.reasons.length > 0 && <ul className="olt-reasons">{h.reasons.map((r) => <li key={r}>{r}</li>)}</ul>}
                </div>
                <div className="onu-hero-actions">
                    {can('nas.manage') && <PostButton action={`${path}/sync`} label={<><Icon name="refresh" />{olt.syncRequested ? 'Read requested' : 'Read now'}</>} className="button" disabled={olt.syncRequested} />}
                    {can('nas.manage') && olt.telnet && <Link className="button primary" href={`${path}/provision`}><Icon name="plus" />New ONUs</Link>}
                    {can('nas.manage') && olt.telnet && <Link className="button" href={`${path}?tab=cli`}><Icon name="terminal" />{can('danger.delete') && olt.canChange ? 'Terminal' : 'Command line'}</Link>}
                    {can('nas.manage') && <Link className="button" href={`${path}/edit`}><Icon name="settings" />Edit</Link>}
                </div>
            </div>
            <div className="olt-hero-body">
                <Chassis hardware={hardware} model={details.version?.product || olt.model} path={path} />
                <div className="olt-kpis">
                    <div className="onu-meter">
                        <div className="onu-meter-head"><span>ONUs online</span><small>{online}%</small></div>
                        <strong className="onu-meter-value">{counts.online}<small> / {counts.total}</small></strong>
                        <span className="olt-ratio" role="img" aria-label={`${online}% online`}><i style={{ width: `${online}%` }} /></span>
                    </div>
                    <Link className={`onu-meter olt-kpi-link${counts.offline ? ' warning' : ''}`} href={`${path}?status=offline`}>
                        <div className="onu-meter-head"><span>Offline</span><small>{counts.offline ? 'Show them' : 'None'}</small></div>
                        <strong className="onu-meter-value">{counts.offline}</strong>
                    </Link>
                    <div className={`onu-meter${counts.weak ? ' warning' : ''}`}>
                        <div className="onu-meter-head"><span>Weak signal</span><small>below {olt.rxWarning} dBm</small></div>
                        <strong className="onu-meter-value">{counts.weak}</strong>
                    </div>
                    <div className={`onu-meter${uplinks.length && up < uplinks.length ? ' danger' : ''}`}>
                        <div className="onu-meter-head"><span>Uplinks up</span><small>{uplinks.map((u) => u.speed ? speedText(u.speed) : '').filter(Boolean)[0] ?? ''}</small></div>
                        <strong className="onu-meter-value">{up}<small> / {uplinks.length}</small></strong>
                    </div>
                </div>
            </div>
            <PonGrid hardware={hardware} olt={olt} path={path} />
            <p className="olt-hero-foot">
                ONU list read <Time value={olt.lastSync} /> (every 5 minutes)
                {olt.telnet ? <> · MAC table read <Time value={olt.lastMacSync} /> (every 15 minutes)</> : ' · Telnet is off, so clients are not found from their MAC'}
                {' · '}<Link href="/olts/mapping">Client mapping</Link>
                {olt.telnet && <> · <a href={`${path}/macs.csv`} download>Export MAC table</a></>}
            </p>
        </section>
    );
}

export default function OltShow({ olt, counts, filters, hardware, details, backups, history, graphs, result }: { olt: Olt; counts: Counts; filters: { q: string; status: string }; hardware: Hardware; details: Details; backups: Backup[] | null; history: HistoryRow[]; graphs: Graphs; result: Paginated<OnuRow> }) {
    const can = useCan();
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const path = `/olts/${olt.id}`;
    return (
        <AppLayout title={olt.name}>
            <nav className="onu-crumbs" aria-label="Where this OLT is">
                <Link href="/olts">OLTs</Link><span aria-hidden="true">/</span><span aria-current="page">{olt.name}</span>
            </nav>
            {olt.error && <p className="flash danger" role="alert">SNMP read failed: {olt.error}</p>}
            {olt.macError && <p className="flash warning" role="alert">Telnet read failed: {olt.macError}</p>}
            <OltHero olt={olt} counts={counts} hardware={hardware} details={details} path={path} />
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
                description={`${result.total} ${filters.q || filters.status ? 'matching' : 'on this OLT'}`}
                tools={(
                    <form className="toolbar olt-onu-filter" onSubmit={(e) => { e.preventDefault(); router.get(path, { q, status }, { preserveState: true }); }}>
                        <nav className="segmented" aria-label="Status">
                            {([['', 'All', counts.total], ['online', 'Online', counts.online], ['offline', 'Offline', counts.offline]] as const).map(([key, label, n]) => (
                                <Link key={key} href={path} data={{ q: filters.q, status: key }} preserveState aria-current={filters.status === key ? 'page' : undefined} onClick={() => setStatus(key)}>{label} <span className="tab-count">{n}</span></Link>
                            ))}
                        </nav>
                        <Field label="Search" name="q" placeholder="Name, serial or client" value={q} onChange={(e) => setQ(e.target.value)} />
                        <button className="button"><Icon name="search" />Search</button>
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
                        { header: 'ONU', className: 'wrap', cell: (o) => <Link className="olt-onu-cell" href={`${path}/onus/${o.id}`}><strong>{o.description || o.sn}</strong><small className="mono">{o.sn}</small></Link> },
                        { header: 'Status', cell: (o) => (o.active === false ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={o.status} />) },
                        { header: 'Port', cell: (o) => <span className="mono">{o.position}</span> },
                        { header: 'Client', cell: (o) => (o.clients.length ? o.clients.map((u) => <div key={u}><Link href={`/clients/${encodeURIComponent(u)}/edit?tab=onu`}>{u}</Link></div>) : <span className="onu-pending">Not linked</span>) },
                        { header: 'Signal ONU / OLT', cell: (o) => <span className="olt-signals"><OnuPower dbm={o.rx} weak={olt.rxWarning} veryWeak={olt.rxCritical} /><OnuPower dbm={o.oltRx} weak={olt.rxWarning} veryWeak={olt.rxCritical} /></span> },
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
                    { key: 'details', title: 'OLT details', icon: 'server', content: <DetailsTab olt={olt} details={details} hardware={hardware} path={path} /> },
                    { key: 'ports', title: 'Ports', icon: 'signal', count: hardware.ports.length, content: <Ports hardware={hardware} olt={olt} path={path} range={graphs.range} /> },
                    { key: 'cards', title: 'Cards', icon: 'server', count: hardware.boards.length, content: <Cards hardware={hardware} olt={olt} path={path} /> },
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
                    { key: 'vlans', title: 'VLANs and profiles', icon: 'services', count: details.vlans.length, content: <VlansTab details={details} olt={olt} hardware={hardware} path={path} /> },
                    ...(backups ? [{ key: 'backups', title: 'Config backups', icon: 'book', count: backups.length, content: <BackupsTab olt={olt} details={details} backups={backups} path={path} /> }] : []),
                    ...(can('nas.manage') && olt.telnet ? [can('danger.delete') && olt.canChange
                        ? { key: 'cli', title: 'Terminal', icon: 'activity', content: <OltConsole path={path} name={olt.name} start={typeof window === 'undefined' ? undefined : new URLSearchParams(window.location.search).get('at') ?? undefined} /> }
                        : { key: 'cli', title: 'Command line', icon: 'activity', content: <CommandLine path={path} full={can('nas.manage') && !can('danger.delete') ? 'role' : !olt.canChange ? 'olt' : null} /> }] : []),
                    { key: 'history', title: 'History', icon: 'logs', count: history.length, content: <HistoryTab rows={history} /> },
                ]}
            />
        </AppLayout>
    );
}
