import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { DeleteButton, PostButton } from '@/components/confirm';
import { ChartOrMessage, type ChartPayload } from '@/components/LineChart';
import { OnuPower, OnuStatus, type OnuRow } from '@/components/olt';
import { DataTable } from '@/components/table';
import { Badge, Field, Panel, Select, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Wan = { name: string; service: string; type: string; status: string; access: string; ip: string; mask: string; gateway: string; dns: string; vlan: string; mac: string };
type Lan = { port: number; type: string; speed: string; duplex: string; up: boolean; on: boolean | null; native: number | null };
type ServicePort = { index: number; vlan: number; gem: number; down: number | null; up: number | null; state: boolean };
type Live = {
    state: { run: string; active: boolean; config: string; match: string; online: string; uptime: string; cpu: string; memory: string; temperature: string; downCause: string; ip: string; lineProfile: string; serviceProfile: string };
    optical: { rx: string; tx: string; oltRx: string; temperature: string; voltage: string; bias: string };
    software: { model: string; version: string; mac: string };
    wans: Wan[];
    lan: Lan[];
    traffic: { up: number | null; down: number | null };
    servicePorts: ServicePort[];
};
type Answer = { ok: true; data: Live } | { ok: false; error: string };
type TrafficTable = { id: number; cir: string; pir: string };
type OltInfo = { id: number; name: string; canRead: boolean; canChange: boolean; rxWarning: number; rxCritical: number };
type Client = { username: string; name: string; address: string; phone: string };

const Row = ({ label, children }: { label: string; children: ReactNode }) => (
    <div className="kv"><span>{label}</span><strong>{children}</strong></div>
);
const dash = (value: string, unit = '') => (value ? `${value}${unit}` : '-');
const speed = (kbps: string | undefined) => (kbps === undefined ? '-' : kbps === 'off' ? 'No limit' : Number(kbps) >= 1000000 ? `${Math.round(Number(kbps) / 100000) / 10} Gbit/s` : Number(kbps) >= 1000 ? `${Math.round(Number(kbps) / 100) / 10} Mbit/s` : `${kbps} kbit/s`);
const rate = (kbps: number | null) => (kbps === null ? '-' : kbps >= 1000 ? `${(kbps / 1000).toFixed(1)} Mbit/s` : `${kbps} kbit/s`);

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(String(response.status));
    return (await response.json()) as T;
}

/** A graph from the portal's own history. Fetched when shown and again every 5 minutes. */
function HistoryGraph({ title, url }: { title: string; url: string }) {
    const [payload, setPayload] = useState<ChartPayload | null>(null);
    useEffect(() => {
        let alive = true;
        const load = () => getJson<ChartPayload>(url).then((data) => { if (alive) setPayload(data); }).catch(() => undefined);
        void load();
        const timer = window.setInterval(load, 300000);
        return () => { alive = false; window.clearInterval(timer); };
    }, [url]);
    return <Panel title={title} className="traffic-panel">{payload ? <ChartOrMessage payload={payload} /> : <p className="hint" role="status">Loading the graph.</p>}</Panel>;
}

function ServicePorts({ ports, tables, base, mayChange }: { ports: ServicePort[]; tables: TrafficTable[]; base: string; mayChange: boolean }) {
    const [editing, setEditing] = useState<{ index: number; up: string; down: string } | null>(null);
    const byId = new Map(tables.map((t) => [t.id, t]));
    const options = tables.map((t) => ({ value: String(t.id), label: `${t.id}: ${speed(t.pir)}` }));
    return (
        <Panel title="Speed profiles">
            <p className="hint">The OLT&apos;s own ceiling for this ONU, per service port. The customer&apos;s plan speed stays with RADIUS.</p>
            <DataTable
                rows={ports}
                rowKey={(p) => p.index}
                emptyText="The OLT lists no service port for this ONU, so it carries no traffic."
                columns={[
                    { header: 'Service port', cell: (p) => p.index },
                    { header: 'VLAN', cell: (p) => p.vlan },
                    { header: 'Download', cell: (p) => (p.down === null ? '-' : `${speed(byId.get(p.down)?.pir)} (table ${p.down})`) },
                    { header: 'Upload', cell: (p) => (p.up === null ? '-' : `${speed(byId.get(p.up)?.pir)} (table ${p.up})`) },
                    { header: 'State', cell: (p) => (p.state ? <Badge tone="success">Up</Badge> : <Badge>Down</Badge>) },
                    ...(mayChange && tables.length ? [{ header: '', cell: (p: ServicePort) => <button type="button" className="button" onClick={() => setEditing({ index: p.index, up: String(p.up ?? ''), down: String(p.down ?? '') })}>Configure</button> }] : []),
                ]}
            />
            {editing && (
                <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${base}/service-ports/${editing.index}`, { up: editing.up, down: editing.down }, { preserveScroll: true, onSuccess: () => setEditing(null) }); }}>
                    <Select label={`Service port ${editing.index}: download`} name="down" placeholder="Choose" options={options} value={editing.down} onChange={(e) => setEditing({ ...editing, down: e.target.value })} />
                    <Select label="Upload" name="up" placeholder="Choose" options={options} value={editing.up} onChange={(e) => setEditing({ ...editing, up: e.target.value })} />
                    <button className="button primary" disabled={!editing.up || !editing.down}>Save on the OLT</button>
                    <button type="button" className="button" onClick={() => setEditing(null)}>Cancel</button>
                </form>
            )}
        </Panel>
    );
}

function LiveDetails({ base, olt, tables, mayChange }: { base: string; olt: OltInfo; tables: TrafficTable[]; mayChange: boolean }) {
    const [answer, setAnswer] = useState<Answer | null>(null);
    const [busy, setBusy] = useState(false);
    const [auto, setAuto] = useState(false);
    const [config, setConfig] = useState<{ ok: boolean; output?: string; error?: string } | null>(null);
    const [configBusy, setConfigBusy] = useState(false);
    const read = useCallback(async () => {
        setBusy(true);
        try {
            setAnswer(await getJson<Answer>(`${base}/live`));
        } catch {
            setAnswer({ ok: false, error: 'The portal could not be reached, or your session ended. Reload the page and try again.' });
        } finally {
            setBusy(false);
        }
    }, [base]);
    useEffect(() => { void read(); }, [read]);
    // Live: asks the OLT again 10 seconds after each answer, for as long as it is on and the page is in view.
    useEffect(() => {
        if (!auto || busy) return undefined;
        const timer = window.setTimeout(() => { if (!document.hidden) void read(); }, 10000);
        return () => window.clearTimeout(timer);
    }, [auto, busy, read]);
    const showConfig = async () => {
        setConfigBusy(true);
        try {
            setConfig(await getJson(`${base}/config`));
        } catch {
            setConfig({ ok: false, error: 'The portal could not be reached. Reload the page and try again.' });
        } finally {
            setConfigBusy(false);
        }
    };

    const tools = (
        <div className="toolbar">
            <button type="button" className="button" disabled={busy} onClick={() => void read()}>{busy ? 'Asking the OLT…' : 'Get status'}</button>
            <button type="button" className="button" disabled={configBusy} onClick={() => (config ? setConfig(null) : void showConfig())}>{configBusy ? 'Asking the OLT…' : config ? 'Hide running config' : 'Show running config'}</button>
            <button type="button" className={`button${auto ? ' primary' : ''}`} aria-pressed={auto} onClick={() => setAuto(!auto)}>{auto ? 'Live: on' : 'Live'}</button>
        </div>
    );
    const configPanel = config && (
        <Panel title="Running config on the OLT">
            {config.ok ? <pre className="mono" style={{ overflowX: 'auto', whiteSpace: 'pre' }} tabIndex={0}>{config.output}</pre> : <p className="flash warning" role="alert">{config.error}</p>}
        </Panel>
    );
    if (!answer) return <><Panel title="Right now" actions={tools}><p className="hint" role="status">Asking the OLT. This takes a few seconds.</p></Panel>{configPanel}</>;
    if (!answer.ok) return <><Panel title="Right now" actions={tools}><p className="flash warning" role="alert">{answer.error}</p></Panel>{configPanel}</>;
    const { state, optical, software, wans, lan, traffic, servicePorts } = answer.data;
    const power = (value: string) => (value && !Number.isNaN(Number(value)) ? <OnuPower dbm={Number(value)} weak={olt.rxWarning} veryWeak={olt.rxCritical} /> : '-');
    const routed = wans.find((w) => /rout/i.test(w.type));
    return (
        <>
            <Panel title="Right now" actions={tools}>
                <Row label="State">{!state.active ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={state.run} />}{state.online ? ` for ${state.online}` : ''}</Row>
                <Row label="Signal at the ONU / at the OLT">{power(optical.rx)} / {power(optical.oltRx)}</Row>
                <Row label="Traffic right now">Download {rate(traffic.down)}, upload {rate(traffic.up)}</Row>
                <Row label="ONU mode">{wans.length ? (routed ? `Routing, WAN VLAN ${routed.vlan || '-'}` : 'Bridging') : state.run === 'online' ? 'Bridging (no connection of its own)' : '-'}</Row>
                <Row label="WAN setup">{routed ? <>{routed.access || '-'} <span className="mono">{routed.ip || ''}</span> {routed.status && <Badge tone={routed.status.toLowerCase() === 'connected' ? 'success' : 'warning'}>{routed.status}</Badge>}</> : '-'}</Row>
                <Row label="Attached VLANs">{servicePorts.length ? [...new Set(servicePorts.map((p) => p.vlan))].join(', ') : '-'}</Row>
                <Row label="ONU transmit power">{dash(optical.tx, ' dBm')}</Row>
                <Row label="CPU / memory">{dash(state.cpu)} / {dash(state.memory)}</Row>
                <Row label="Temperature: device / optical module">{dash(state.temperature)} / {dash(optical.temperature, ' °C')}</Row>
                <Row label="Running since restart">{dash(state.uptime)}</Row>
                <Row label="Software">{dash(software.version)}</Row>
                <Row label="Model / MAC"><span className="mono">{dash(software.model)} / {dash(software.mac)}</span></Row>
                <Row label="Settings pushed by the OLT (OMCI)">{dash(state.config)}{state.match ? `, profile ${state.match}` : ''}</Row>
                <Row label="Reason it last went offline">{dash(state.downCause)}</Row>
            </Panel>
            {configPanel}
            <ServicePorts ports={servicePorts} tables={tables} base={base} mayChange={mayChange} />
            <Panel title="Ethernet ports">
                <DataTable
                    rows={lan}
                    rowKey={(p) => p.port}
                    emptyText="The ONU did not report its LAN ports. It may be offline."
                    columns={[
                        { header: 'Port', cell: (p) => `eth ${p.port}` },
                        { header: 'Admin state', cell: (p) => (p.on === null ? '-' : p.on ? 'Enabled' : <Badge tone="warning">Turned off</Badge>) },
                        { header: 'Link', cell: (p) => (p.up ? <Badge tone="success">Up</Badge> : <Badge>Down</Badge>) },
                        { header: 'Speed', cell: (p) => (p.speed ? `${p.speed} Mbit/s${p.duplex ? `, ${p.duplex} duplex` : ''}` : '-') },
                        { header: 'Native VLAN', cell: (p) => p.native ?? '-' },
                        ...(mayChange ? [{ header: '', cell: (p: Lan) => (p.on === null ? '' : p.on
                            ? <PostButton action={`${base}/lan/${p.port}`} data={{ state: 'off' }} label="Turn off" confirm={{ title: `Turn LAN port ${p.port} off?`, message: 'Whatever is plugged into this port on the ONU loses its connection.', confirmLabel: 'Turn off' }} />
                            : <PostButton action={`${base}/lan/${p.port}`} data={{ state: 'on' }} label="Turn on" className="link" />) }] : []),
                    ]}
                />
            </Panel>
            <Panel title="Internet connections on the ONU">
                <DataTable
                    rows={wans}
                    rowKey={(w) => w.name + w.mac}
                    emptyText="The ONU reports no connection of its own. That is normal for an ONU in bridge mode, or one that is offline."
                    columns={[
                        { header: 'Name', className: 'wrap', cell: (w) => w.name || '-' },
                        { header: 'Status', cell: (w) => (w.status ? <Badge tone={w.status.toLowerCase() === 'connected' ? 'success' : 'warning'}>{w.status}</Badge> : '-') },
                        { header: 'Type', cell: (w) => [w.type, w.access].filter(Boolean).join(', ') || '-' },
                        { header: 'IP address', cell: (w) => <span className="mono">{w.ip || '-'}</span> },
                        { header: 'Gateway', cell: (w) => <span className="mono">{w.gateway || '-'}</span> },
                        { header: 'DNS', cell: (w) => <span className="mono">{w.dns || '-'}</span> },
                        { header: 'VLAN', cell: (w) => w.vlan || '-' },
                        { header: 'MAC', cell: (w) => <span className="mono">{w.mac || '-'}</span> },
                    ]}
                />
            </Panel>
        </>
    );
}

export default function OnuPage({ olt, onu, clients, disconnections, trafficTables, ranges }: { olt: OltInfo; onu: OnuRow; clients: Client[]; disconnections: { day: number; week: number }; trafficTables: TrafficTable[]; ranges: Record<string, string> }) {
    const can = useCan();
    const base = `/olts/${olt.id}/onus/${onu.id}`;
    const [description, setDescription] = useState(onu.description);
    const [range, setRange] = useState('24h');
    const mayChange = can('nas.manage') && olt.canChange;
    const off = onu.active === false;
    const [slot, port] = onu.position.split(':')[0].split('/').slice(1);
    return (
        <AppLayout title={`ONU ${onu.sn}`}>
            <div className="toolbar">
                <Link className="button" href={`/olts/${olt.id}`}>{olt.name}</Link>
                <Badge tone={disconnections.day ? 'warning' : ''}>Disconnections: {disconnections.day} (24 h) · {disconnections.week} (7 d)</Badge>
            </div>
            {can('nas.manage') && !olt.canChange && <p className="hint">Changes are turned off for this OLT, so this page only reads. To restart, turn off, rename or remove ONUs here, turn on <Link href={`/olts/${olt.id}/edit`}>Allow changes from the portal</Link>.</p>}
            <Panel title="ONU">
                <Row label="Status">{off ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={onu.status} />}{onu.distance !== null ? `, ${onu.distance} m away` : ''}</Row>
                <Row label="Signal at the ONU / at the OLT"><OnuPower dbm={onu.rx} weak={olt.rxWarning} veryWeak={olt.rxCritical} /> / <OnuPower dbm={onu.oltRx} weak={olt.rxWarning} veryWeak={olt.rxCritical} /></Row>
                <Row label="OLT"><Link href={`/olts/${olt.id}?q=${encodeURIComponent(onu.sn)}`}>{olt.name}</Link></Row>
                <Row label="Board / port / ONU"><span className="mono">{slot} / {port} / {onu.position}</span></Row>
                <Row label="Serial number"><span className="mono">{onu.sn}</span></Row>
                <Row label="ONU type">{onu.model || '-'}{onu.serviceProfile ? ` (service profile ${onu.serviceProfile})` : ''}</Row>
                <Row label="Line profile">{onu.profile || '-'}</Row>
                <Row label="Client">{clients.length ? clients.map((c) => <div key={c.username}><Link href={`/clients/${encodeURIComponent(c.username)}/edit?tab=onu`}>{c.username}</Link>{c.name ? ` (${c.name})` : ''}</div>) : 'Not linked to a client'}</Row>
                {clients.map((c) => (c.address || c.phone) && <Row key={c.username} label="Address and contact">{[c.address, c.phone].filter(Boolean).join(' · ')}</Row>)}
                <Row label="Last came online"><Time value={onu.lastUp} /></Row>
                <Row label="Last went offline"><Time value={onu.lastDown} />{onu.downCause ? ` (${onu.downCause})` : ''}</Row>
                <Row label="Read from the OLT"><Time value={onu.seen} /></Row>
            </Panel>
            <Panel title="Description on the OLT">
                {mayChange ? (
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${base}/describe`, { description }, { preserveScroll: true }); }}>
                        <Field label="Description" name="description" maxLength={64} value={description} onChange={(e) => setDescription(e.target.value)} hint="Letters, digits and simple punctuation, up to 64 characters. Letters such as ë and ç are saved as e and c." />
                        <button className="button" disabled={!description.trim() || description === onu.description}>Save on the OLT</button>
                    </form>
                ) : <p>{onu.description || '-'}</p>}
            </Panel>
            <div className="toolbar">
                <Select label="Graphs show" name="range" options={ranges} value={range} onChange={(e) => setRange(e.target.value)} />
            </div>
            <HistoryGraph title="Traffic history" url={`${base}/graph?chart=traffic&range=${range}`} />
            <HistoryGraph title="Signal history" url={`${base}/graph?chart=signal&range=${range}`} />
            {olt.canRead ? <LiveDetails base={base} olt={olt} tables={trafficTables} mayChange={mayChange} /> : <Panel title="Right now"><p className="hint">Live details need telnet turned on for this OLT.</p></Panel>}
            {mayChange && (
                <Panel title="Actions">
                    <div className="toolbar">
                        <PostButton action={`${base}/reboot`} label="Reboot" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Restart this ONU?', message: `${onu.description || onu.sn} loses its connection for a minute or two.`, confirmLabel: 'Restart' }} />
                        {off
                            ? <PostButton action={`${base}/enable`} label="Enable ONU" className="button" />
                            : <PostButton action={`${base}/disable`} label="Disable ONU" className="button" confirm={{ title: 'Turn this ONU off?', message: `${onu.description || onu.sn} loses its connection until you turn the ONU on again. Its settings stay on the OLT.`, confirmLabel: 'Turn off' }} />}
                        {can('danger.delete') && (
                            <>
                                <PostButton action={`${base}/factory-reset`} label="Restore defaults" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Put this ONU back to its factory settings?', message: 'The customer\'s own Wi-Fi name and password and anything else set on the ONU are lost. The ONU restarts, and the OLT sets its internet connection up again.', confirmLabel: 'Restore defaults', typeToConfirm: onu.sn }} />
                                <DeleteButton action={`${base}/delete`} label="Delete" title={`Remove ${onu.sn} from the OLT?`} message="The ONU and its service ports are deleted on the OLT, and its client is unlinked. The customer is offline until the ONU is added again." typeToConfirm={onu.sn} />
                            </>
                        )}
                    </div>
                </Panel>
            )}
        </AppLayout>
    );
}
