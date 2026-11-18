import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { DeleteButton, PostButton, useConfirm } from '@/components/confirm';
import Icon from '@/components/Icon';
import { ChartOrMessage, type ChartPayload } from '@/components/LineChart';
import { OnuStatus, type OnuRow } from '@/components/olt';
import { DataTable } from '@/components/table';
import { Tabs, type TabDef } from '@/components/tabs';
import { Badge, Check, Field, Panel, Select, Time } from '@/components/ui';
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
type Client = { username: string; name: string; address: string; phone: string; pppoe: boolean };
type Profile = { id: number; name: string; bound: number };
type OmciOptions = { vlans: number[]; wanProfiles: Profile[]; routeProfiles: Profile[] };
type Segment = { from: number; to: number; state: 'online' | 'offline' | 'none' };
type Timeline = { from: number; to: number; segments: Segment[] };
type LiveState = { answer: Answer | null; busy: boolean; auto: boolean; setAuto: (on: boolean) => void; read: () => Promise<void>; enabled: boolean };

const dash = (value: string | number | null | undefined, unit = '') => (value === null || value === undefined || value === '' ? '-' : `${value}${unit}`);
const speed = (kbps: string | undefined) => (kbps === undefined ? '-' : kbps === 'off' ? 'No limit' : Number(kbps) >= 1000000 ? `${Math.round(Number(kbps) / 100000) / 10} Gbit/s` : Number(kbps) >= 1000 ? `${Math.round(Number(kbps) / 100) / 10} Mbit/s` : `${kbps} kbit/s`);
const rate = (kbps: number | null | undefined) => (kbps === null || kbps === undefined ? '-' : kbps >= 1000 ? `${(kbps / 1000).toFixed(1)} Mbit/s` : `${kbps} kbit/s`);
const num = (value: string | undefined) => (value && !Number.isNaN(Number(value)) ? Number(value) : null);
const clock = (seconds: number) => new Date(seconds * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });

/** 10h 55m, 3d 4h, 12m: the two largest units are enough to read at a glance. */
function duration(seconds: number | null): string {
    if (seconds === null) return '';
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (d) return `${d}d ${h}h`;
    if (h) return `${h}h ${m}m`;
    return `${Math.max(m, 1)}m`;
}

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(String(response.status));
    return (await response.json()) as T;
}

/** One read of the OLT shared by the whole page. Live asks again 10 seconds after each answer while the page is in view. */
function useLive(base: string, enabled: boolean): LiveState {
    const [answer, setAnswer] = useState<Answer | null>(null);
    const [busy, setBusy] = useState(false);
    const [auto, setAuto] = useState(false);
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
    useEffect(() => { if (enabled) void read(); }, [enabled, read]);
    useEffect(() => {
        if (!enabled || !auto || busy) return undefined;
        const timer = window.setTimeout(() => { if (!document.hidden) void read(); }, 10000);
        return () => window.clearTimeout(timer);
    }, [enabled, auto, busy, read]);
    return { answer, busy, auto, setAuto, read, enabled };
}

function signalTone(dbm: number | null, weak: number, veryWeak: number): string {
    if (dbm === null) return '';
    if (dbm < veryWeak) return 'danger';
    if (dbm < weak || dbm > -8) return 'warning';
    return 'success';
}

/**
 * A received-power reading on a scale from -35 to -5 dBm, with the OLT's own weak and very weak levels marked,
 * so the number is read against its limits. The words say the same as the colour.
 */
function SignalMeter({ label, dbm, weak, veryWeak, note }: { label: string; dbm: number | null; weak: number; veryWeak: number; note?: string }) {
    const min = -35;
    const span = 30;
    const at = (v: number) => `${Math.min(100, Math.max(0, ((v - min) / span) * 100))}%`;
    const tone = signalTone(dbm, weak, veryWeak);
    const verdict = dbm === null ? 'No reading' : tone === 'danger' ? 'Very weak' : dbm > -8 ? 'Too strong' : tone === 'warning' ? 'Weak' : 'Good';
    return (
        <div className={`onu-meter ${tone}`.trim()}>
            <div className="onu-meter-head">
                <span>{label}</span>
                <small>{verdict}</small>
            </div>
            <strong className="onu-meter-value">{dbm === null ? '-' : dbm.toFixed(2)}<small> dBm</small></strong>
            <div className="onu-meter-scale" style={{ ['--weak' as string]: at(weak), ['--very-weak' as string]: at(veryWeak), ['--strong' as string]: at(-8) }} aria-hidden="true">
                {dbm !== null && <span className="onu-meter-needle" style={{ left: at(dbm) }} />}
            </div>
            <div className="onu-meter-ticks" aria-hidden="true">{[min, veryWeak, weak, -8].map((v) => <span key={v} style={{ left: at(v) }}>{v}</span>)}</div>
            {note && <small className="onu-meter-note">{note}</small>}
        </div>
    );
}

/** A generic ONU seen from the back: the PON port, one socket per LAN port lit by its link, and the power light. */
function DeviceArt({ online, off, lan }: { online: boolean; off: boolean; lan: Lan[] | null }) {
    const ports = lan && lan.length ? lan : Array.from({ length: 4 }, (_, i) => ({ port: i + 1, up: false, on: null }) as Lan);
    const shown = ports.slice(0, 8);
    const width = 360;
    const first = 128;
    const step = Math.min(44, (width - first - 70) / shown.length);
    const state = (p: Lan) => (!lan ? 'unknown' : p.on === false ? 'off' : p.up ? 'up' : 'down');
    return (
        <svg className="onu-art" viewBox={`0 0 ${width} 110`} role="img" aria-label={lan ? `ONU with ${shown.length} LAN ports, ${shown.filter((p) => p.up).length} with a link` : 'ONU'}>
            <rect className="onu-art-shadow" x="14" y="92" width={width - 28} height="10" rx="5" />
            <rect className="onu-art-body" x="8" y="14" width={width - 16} height="78" rx="12" />
            <rect className="onu-art-face" x="18" y="26" width={width - 36} height="54" rx="6" />
            <g>
                <rect className={`onu-art-pon ${off ? 'off' : online ? 'up' : 'down'}`} x="32" y="38" width="26" height="22" rx="3" />
                <circle className="onu-art-pon-core" cx="45" cy="49" r="5" />
                <text className="onu-art-label" x="45" y="73" textAnchor="middle">PON</text>
            </g>
            <line className="onu-art-divider" x1="80" y1="34" x2="80" y2="72" />
            <g>
                <rect className="onu-art-phone" x="92" y="40" width="22" height="18" rx="2" />
                <text className="onu-art-label" x="103" y="73" textAnchor="middle">TEL</text>
            </g>
            {shown.map((p, i) => {
                const x = first + i * step;
                return (
                    <g key={p.port} className={`onu-art-lan ${state(p)}`}>
                        <title>{`LAN ${p.port}: ${state(p) === 'up' ? `link up${p.speed ? `, ${p.speed} Mbit/s` : ''}` : state(p) === 'off' ? 'turned off' : state(p) === 'down' ? 'no link' : 'not read yet'}`}</title>
                        <rect className="onu-art-socket" x={x} y="36" width="32" height="26" rx="3" />
                        <rect className="onu-art-jack" x={x + 6} y="42" width="20" height="14" rx="1.5" />
                        <circle className="onu-art-led" cx={x + 6} cy="33" r="2.2" />
                        <text className="onu-art-label" x={x + 16} y="73" textAnchor="middle">{`LAN${p.port}`}</text>
                    </g>
                );
            })}
            <circle className="onu-art-reset" cx={width - 56} cy="49" r="3" />
            <circle className={`onu-art-power ${off ? 'off' : 'on'}`} cx={width - 34} cy="49" r="8" />
        </svg>
    );
}

/** The last 24 hours as one strip, read every 5 minutes. Gaps in the reads show as hatched, not as offline. */
function StatusStrip({ timeline, disconnections }: { timeline: Timeline; disconnections: { day: number; week: number } }) {
    const total = timeline.to - timeline.from;
    const known = timeline.segments.filter((s) => s.state !== 'none').reduce((sum, s) => sum + (s.to - s.from), 0);
    const online = timeline.segments.filter((s) => s.state === 'online').reduce((sum, s) => sum + (s.to - s.from), 0);
    const share = known ? Math.round((online / known) * 1000) / 10 : null;
    const ticks = [0, 1, 2, 3, 4].map((i) => timeline.from + (total * i) / 4);
    const words = { online: 'Online', offline: 'Offline', none: 'No reading' };
    return (
        <div className="onu-strip">
            <div className="onu-strip-head">
                <h3>Last 24 hours</h3>
                <span>{share === null ? 'No reads yet' : `${share}% online`} · {disconnections.day} disconnection{disconnections.day === 1 ? '' : 's'} today · {disconnections.week} in 7 days</span>
            </div>
            <div className="onu-strip-bar" role="img" aria-label={`${share === null ? 'No reads in the last 24 hours' : `Online ${share}% of the time it was read in the last 24 hours`}, ${disconnections.day} disconnections.`}>
                {timeline.segments.map((s) => (
                    <span key={s.from} className={`onu-strip-seg ${s.state}`} style={{ flexGrow: s.to - s.from }} title={`${words[s.state]} ${clock(s.from)} to ${clock(s.to)} (${duration(s.to - s.from)})`} />
                ))}
            </div>
            <div className="onu-strip-ticks" aria-hidden="true">{ticks.map((t) => <span key={t}>{clock(t)}</span>)}</div>
            <div className="onu-strip-legend" aria-hidden="true">
                <span><i className="online" />Online</span><span><i className="offline" />Offline</span><span><i className="none" />No reading</span>
            </div>
        </div>
    );
}

function Facts({ title, icon, children, aside }: { title: string; icon: string; children: ReactNode; aside?: ReactNode }) {
    return (
        <section className="onu-facts">
            <header><Icon name={icon} /><h3>{title}</h3>{aside}</header>
            <dl>{children}</dl>
        </section>
    );
}
const Fact = ({ label, children, mono = false }: { label: string; children: ReactNode; mono?: boolean }) => (
    <div><dt>{label}</dt><dd className={mono ? 'mono' : undefined}>{children}</dd></div>
);

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

function Graphs({ base, ranges }: { base: string; ranges: Record<string, string> }) {
    const [range, setRange] = useState('24h');
    return (
        <div className="onu-tab-body">
            <div className="toolbar"><Select label="Graphs show" name="range" options={ranges} value={range} onChange={(e) => setRange(e.target.value)} /></div>
            <div className="onu-graphs">
                <HistoryGraph title="Traffic" url={`${base}/graph?chart=traffic&range=${range}`} />
                <HistoryGraph title="Signal" url={`${base}/graph?chart=signal&range=${range}`} />
            </div>
        </div>
    );
}

/** Asked for when the tab opens, not with the page: it is a slower read. */
function RunningConfig({ base }: { base: string }) {
    const [config, setConfig] = useState<{ ok: boolean; output?: string; error?: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async () => {
        setBusy(true);
        try {
            setConfig(await getJson(`${base}/config`));
        } catch {
            setConfig({ ok: false, error: 'The portal could not be reached. Reload the page and try again.' });
        } finally {
            setBusy(false);
        }
    }, [base]);
    useEffect(() => { void load(); }, [load]);
    return (
        <Panel title="Running config on the OLT" actions={<button type="button" className="button" disabled={busy} onClick={() => void load()}><Icon name="refresh" />{busy ? 'Asking the OLT…' : 'Read again'}</button>}>
            {!config ? <p className="hint" role="status">Asking the OLT. This takes a few seconds.</p>
                : config.ok ? <pre className="mono onu-config" tabIndex={0}>{config.output}</pre>
                    : <p className="flash warning" role="alert">{config.error}</p>}
        </Panel>
    );
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
                    { header: 'GEM', cell: (p) => p.gem },
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

function Ports({ live, base, mayChange }: { live: Live; base: string; mayChange: boolean }) {
    return (
        <div className="onu-tab-body">
            <Panel title="Ethernet ports">
                <DataTable
                    rows={live.lan}
                    rowKey={(p) => p.port}
                    emptyText="The ONU did not report its LAN ports. It may be offline."
                    columns={[
                        { header: 'Port', cell: (p) => `LAN ${p.port}` },
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
                    rows={live.wans}
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
        </div>
    );
}

/** The ONU's name (its description on the OLT), edited where it is shown. */
function RenameTitle({ name, sn, base, mayChange }: { name: string; sn: string; base: string; mayChange: boolean }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(name);
    const [busy, setBusy] = useState(false);
    useEffect(() => setValue(name), [name]);
    if (!editing) {
        return (
            <div className="onu-title">
                <h2>{name || sn}</h2>
                {mayChange && <button type="button" className="onu-icon-button" onClick={() => setEditing(true)} aria-label="Rename this ONU" title="Rename this ONU on the OLT"><Icon name="edit" /></button>}
            </div>
        );
    }
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        router.post(`${base}/describe`, { description: value }, { preserveScroll: true, onSuccess: () => setEditing(false), onFinish: () => setBusy(false) });
    };
    return (
        <form className="onu-rename" onSubmit={save}>
            <Field label="Name on the OLT" name="description" maxLength={64} value={value} autoFocus onChange={(e) => setValue(e.target.value)} onKeyDown={(e) => { if (e.key === 'Escape') { setValue(name); setEditing(false); } }} hint="Up to 64 letters, digits and simple punctuation. ë and ç are saved as e and c." />
            <div className="onu-rename-actions">
                <button className="button primary" disabled={busy || !value.trim() || value === name}>{busy ? 'Saving on the OLT…' : 'Save'}</button>
                <button type="button" className="button" onClick={() => { setValue(name); setEditing(false); }}>Cancel</button>
            </div>
        </form>
    );
}

type WanMode = 'dhcp' | 'pppoe' | 'static';
const MODES: { value: WanMode; title: string; text: string }[] = [
    { value: 'dhcp', title: 'DHCP', text: 'The ONU asks the network for its address.' },
    { value: 'pppoe', title: 'PPPoE', text: 'The ONU logs in, with the client\'s RADIUS login or one you type.' },
    { value: 'static', title: 'Fixed address', text: 'The ONU uses an address you set.' },
];
const PRIORITIES = Array.from({ length: 8 }, (_, i) => ({ value: String(i), label: i === 0 ? '0 (normal)' : String(i) }));

/**
 * The ONU's internet connection, pushed by the OLT over OMCI. Starts from what the ONU has now, and shows the
 * commands the OLT will get before they are sent.
 */
function WanForm({ base, live, clients, options, onDone }: { base: string; live: Live | null; clients: Client[]; options: OmciOptions; onDone: () => void }) {
    const ask = useConfirm();
    const routed = live?.wans.find((w) => /rout/i.test(w.type));
    const access = (routed?.access ?? '').toLowerCase();
    const radius = clients.filter((c) => c.pppoe);
    const [busy, setBusy] = useState(false);
    const [f, setF] = useState(() => ({
        mode: (access.includes('pppoe') ? 'pppoe' : access.includes('static') ? 'static' : 'dhcp') as WanMode,
        vlan: routed?.vlan && options.vlans.includes(Number(routed.vlan)) ? routed.vlan : '',
        priority: '0', ipIndex: '1', wanProfile: '', routeProfile: '', routeLan: true,
        source: radius.length ? `client:${radius[0].username}` : 'typed', user: '', password: '',
        ip: access.includes('static') ? routed?.ip ?? '' : '', mask: routed?.mask || '255.255.255.0', gateway: access.includes('static') ? routed?.gateway ?? '' : '',
        dns1: '', dns2: '',
    }));
    const set = <K extends keyof typeof f>(key: K, value: (typeof f)[K]) => setF((now) => ({ ...now, [key]: value }));
    const client = f.source.startsWith('client:') ? f.source.slice(7) : '';
    const ready = f.vlan !== '' && (f.mode === 'dhcp'
        || (f.mode === 'pppoe' && (client !== '' || (f.user.trim() !== '' && f.password !== '')))
        || (f.mode === 'static' && f.ip.trim() !== '' && f.mask.trim() !== '' && f.gateway.trim() !== '' && f.dns1.trim() !== ''));

    const head = `ont ipconfig <port> <onu> ip-index ${f.ipIndex}`;
    const tail = `vlan ${f.vlan || '?'} priority ${f.priority}`;
    const preview = [
        f.mode === 'dhcp' ? `${head} dhcp ${tail}`
            : f.mode === 'pppoe' ? `${head} pppoe ${tail} user-account username ${client || f.user || '?'} password ******`
                : `${head} static ip-address ${f.ip || '?'} mask ${f.mask || '?'} gateway ${f.gateway || '?'} pri-dns ${f.dns1 || '?'}${f.dns2 ? ` slave-dns ${f.dns2}` : ''} ${tail}`,
        `ont internet-config <port> <onu> ip-index ${f.ipIndex}`,
        ...(f.wanProfile ? [`ont wan-config <port> <onu> ip-index ${f.ipIndex} profile-id ${f.wanProfile}`] : []),
        ...(f.routeProfile ? [`ont policy-route-config <port> <onu> profile-id ${f.routeProfile}`] : []),
        ...(f.routeLan ? ['ont port route <port> <onu> eth <each LAN port> enable'] : []),
    ];

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!(await ask({ title: 'Push this connection to the ONU?', message: 'The OLT sends it to the ONU over OMCI. The customer\'s internet drops for a moment while the ONU takes it.', confirmLabel: 'Push to the ONU' }))) return;
        const data: Record<string, string> = { mode: f.mode, vlan: f.vlan, priority: f.priority, ip_index: f.ipIndex, wan_profile: f.wanProfile, route_profile: f.routeProfile, route_lan: f.routeLan ? '1' : '' };
        if (f.mode === 'pppoe') Object.assign(data, client ? { pppoe_client: client } : { pppoe_user: f.user.trim(), pppoe_password: f.password });
        if (f.mode === 'static') Object.assign(data, { ip: f.ip.trim(), mask: f.mask.trim(), gateway: f.gateway.trim(), dns1: f.dns1.trim(), dns2: f.dns2.trim() });
        setBusy(true);
        router.post(`${base}/wan`, data, { preserveScroll: true, onSuccess: () => { set('password', ''); onDone(); }, onFinish: () => setBusy(false) });
    };

    return (
        <Panel title="Internet connection (WAN)">
            <p className="hint">Set on the ONU by the OLT over OMCI, so it works on any ONU without TR-069.{routed ? <> Now: <strong>{routed.access || routed.type}</strong> on VLAN <strong>{routed.vlan || '-'}</strong>{routed.ip ? <>, <span className="mono">{routed.ip}</span></> : null}.</> : live ? ' The ONU has no routed connection now.' : ''}</p>
            <form className="form-grid" onSubmit={(e) => void submit(e)}>
                <fieldset className="onu-choice">
                    <legend>How the ONU gets its address</legend>
                    {MODES.map((m) => (
                        <label key={m.value} className={f.mode === m.value ? 'on' : undefined}>
                            <input type="radio" name="mode" value={m.value} checked={f.mode === m.value} onChange={() => set('mode', m.value)} />
                            <strong>{m.title}</strong><small>{m.text}</small>
                        </label>
                    ))}
                </fieldset>
                <Select label="VLAN" name="vlan" placeholder="Choose" value={f.vlan} onChange={(e) => set('vlan', e.target.value)} options={options.vlans.map((v) => ({ value: String(v), label: `VLAN ${v}` }))} hint="Only the VLANs the OLT has." />
                <Select label="Priority (802.1p)" name="priority" value={f.priority} onChange={(e) => set('priority', e.target.value)} options={PRIORITIES} />

                {f.mode === 'pppoe' && (
                    <>
                        <Select label="PPPoE login" name="source" className="span2" value={f.source} onChange={(e) => set('source', e.target.value)}
                            options={[...radius.map((c) => ({ value: `client:${c.username}`, label: `RADIUS login of ${c.name || c.username} (${c.username})` })), { value: 'typed', label: 'Type a username and password' }]}
                            hint={client ? 'The password is taken from RADIUS on the server. It is not shown here and is not kept in the log.' : 'Letters, digits and . _ @ + = ! # $ % & * - only, no spaces or quotes.'} />
                        {!client && (
                            <>
                                <Field label="Username" name="pppoe_user" autoComplete="off" maxLength={64} value={f.user} onChange={(e) => set('user', e.target.value)} />
                                <Field label="Password" name="pppoe_password" type="password" autoComplete="new-password" maxLength={64} value={f.password} onChange={(e) => set('password', e.target.value)} />
                            </>
                        )}
                    </>
                )}
                {f.mode === 'static' && (
                    <>
                        <Field label="Address" name="ip" inputMode="decimal" placeholder="10.20.0.2" value={f.ip} onChange={(e) => set('ip', e.target.value)} />
                        <Field label="Subnet mask" name="mask" inputMode="decimal" value={f.mask} onChange={(e) => set('mask', e.target.value)} />
                        <Field label="Gateway" name="gateway" inputMode="decimal" placeholder="10.20.0.1" value={f.gateway} onChange={(e) => set('gateway', e.target.value)} />
                        <Field label="DNS server" name="dns1" inputMode="decimal" placeholder="1.1.1.1" value={f.dns1} onChange={(e) => set('dns1', e.target.value)} />
                        <Field label="Second DNS server (optional)" name="dns2" inputMode="decimal" value={f.dns2} onChange={(e) => set('dns2', e.target.value)} />
                    </>
                )}

                <Check className="check onu-check" label="Route all LAN ports through this connection (needed for routing mode)" checked={f.routeLan} onChange={(e) => set('routeLan', e.target.checked)} />
                <details>
                    <summary>More: profiles and connection number</summary>
                    <div className="form-grid">
                        <Select label="WAN profile" name="wan_profile" value={f.wanProfile} onChange={(e) => set('wanProfile', e.target.value)} options={[{ value: '', label: 'Leave as it is' }, ...options.wanProfiles.map((p) => ({ value: String(p.id), label: `${p.id}: ${p.name}` }))]} />
                        <Select label="Route policy profile" name="route_profile" value={f.routeProfile} onChange={(e) => set('routeProfile', e.target.value)} options={[{ value: '', label: 'Leave as it is' }, ...options.routeProfiles.map((p) => ({ value: String(p.id), label: `${p.id}: ${p.name}` }))]} />
                        <Select label="Connection number (ip-index)" name="ip_index" value={f.ipIndex} onChange={(e) => set('ipIndex', e.target.value)} options={Array.from({ length: 8 }, (_, i) => ({ value: String(i), label: i === 1 ? '1 (as ONUs are added)' : String(i) }))} hint="Use the same number again to change a connection rather than add a second one." />
                    </div>
                </details>
                <details>
                    <summary>Commands the OLT will get</summary>
                    <pre className="mono onu-preview">{preview.join('\n')}</pre>
                </details>
                <div className="form-actions">
                    <button className="button primary" disabled={busy || !ready}>{busy ? 'Pushing over OMCI…' : 'Push to the ONU'}</button>
                    {!ready && <span className="hint">{f.vlan === '' ? 'Choose a VLAN.' : 'Fill in the fields above.'}</span>}
                </div>
            </form>
        </Panel>
    );
}

/** The VLAN each LAN port puts untagged traffic on: what a bridged ONU needs. */
function LanVlans({ base, live, vlans, onDone }: { base: string; live: Live; vlans: number[]; onDone: () => void }) {
    const [chosen, setChosen] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState<number | null>(null);
    return (
        <Panel title="LAN port VLANs">
            <p className="hint">For bridging: the VLAN a LAN port puts untagged traffic on. Set by the OLT over OMCI.</p>
            <DataTable
                rows={live.lan}
                rowKey={(p) => p.port}
                emptyText="The ONU did not report its LAN ports. It may be offline."
                columns={[
                    { header: 'Port', cell: (p) => `LAN ${p.port}` },
                    { header: 'Link', cell: (p) => (p.up ? <Badge tone="success">Up</Badge> : <Badge>Down</Badge>) },
                    { header: 'Native VLAN now', cell: (p) => p.native ?? '-' },
                    {
                        header: 'Set to', cell: (p) => (
                            <select aria-label={`Native VLAN for LAN ${p.port}`} value={chosen[p.port] ?? String(p.native ?? '')} onChange={(e) => setChosen({ ...chosen, [p.port]: e.target.value })}>
                                {p.native === null && <option value="">Choose</option>}
                                {p.native !== null && !vlans.includes(p.native) && <option value={String(p.native)}>{p.native}</option>}
                                {vlans.map((v) => <option key={v} value={String(v)}>VLAN {v}</option>)}
                            </select>
                        ),
                    },
                    {
                        header: '', cell: (p) => {
                            const to = chosen[p.port];
                            return (
                                <button type="button" className="button" disabled={busy !== null || !to || to === String(p.native ?? '')}
                                    onClick={() => { setBusy(p.port); router.post(`${base}/lan-vlan/${p.port}`, { vlan: to, priority: 0 }, { preserveScroll: true, onSuccess: onDone, onFinish: () => setBusy(null) }); }}>
                                    {busy === p.port ? 'Saving…' : 'Save'}
                                </button>
                            );
                        },
                    },
                ]}
            />
        </Panel>
    );
}

function Configure({ base, live, clients }: { base: string; live: LiveState; clients: Client[] }) {
    const [options, setOptions] = useState<{ ok: true; data: OmciOptions } | { ok: false; error: string } | null>(null);
    useEffect(() => {
        let alive = true;
        getJson<{ ok: true; data: OmciOptions } | { ok: false; error: string }>(`${base}/omci-options`)
            .then((answer) => { if (alive) setOptions(answer); })
            .catch(() => { if (alive) setOptions({ ok: false, error: 'The portal could not be reached. Reload the page and try again.' }); });
        return () => { alive = false; };
    }, [base]);
    if (!options) return <Panel><p className="hint" role="status">Reading the OLT&apos;s VLANs and profiles.</p></Panel>;
    if (!options.ok) return <Panel><p className="flash warning" role="alert">{options.error}</p></Panel>;
    const data = live.answer?.ok ? live.answer.data : null;
    // The form starts from what the ONU has now, so it waits for the first read.
    if (live.enabled && !live.answer) return <Panel><p className="hint" role="status">Reading what the ONU has now.</p></Panel>;
    const reread = () => { window.setTimeout(() => void live.read(), 3000); };
    return (
        <div className="onu-tab-body">
            <WanForm base={base} live={data} clients={clients} options={options.data} onDone={reread} />
            {data ? <LanVlans base={base} live={data} vlans={options.data.vlans} onDone={reread} /> : <Waiting live={live} />}
        </div>
    );
}

/** What a tab that needs the OLT shows until the OLT has answered. */
function Waiting({ live }: { live: LiveState }) {
    if (!live.enabled) return <Panel><p className="hint">Live details need telnet turned on for this OLT.</p></Panel>;
    if (!live.answer) return <Panel><p className="hint" role="status">Asking the OLT. This takes a few seconds.</p></Panel>;
    if (!live.answer.ok) return <Panel><p className="flash warning" role="alert">{live.answer.error}</p></Panel>;
    return null;
}

export default function OnuPage({ olt, onu, clients, disconnections, timeline, since, trafficTables, ranges }: {
    olt: OltInfo; onu: OnuRow; clients: Client[]; disconnections: { day: number; week: number }; timeline: Timeline; since: number | null; trafficTables: TrafficTable[]; ranges: Record<string, string>;
}) {
    const can = useCan();
    const base = `/olts/${olt.id}/onus/${onu.id}`;
    const mayChange = can('nas.manage') && olt.canChange;
    const off = onu.active === false;
    const live = useLive(base, olt.canRead);
    const data = live.answer?.ok ? live.answer.data : null;
    const [frame, slot, port] = onu.position.split(':')[0].split('/');
    const onuId = onu.position.split(':')[1] ?? '';

    // A fresh read from the OLT wins over the last regular read for the numbers at the top.
    const status = data ? (!data.state.active ? 'off' : data.state.run) : off ? 'off' : onu.status;
    const rx = num(data?.optical.rx) ?? onu.rx;
    const oltRx = num(data?.optical.oltRx) ?? onu.oltRx;
    const routed = data?.wans.find((w) => /rout/i.test(w.type));
    const vlans = data ? [...new Set(data.servicePorts.map((p) => p.vlan))] : [];
    const waiting = !olt.canRead ? 'Needs telnet' : live.answer && !live.answer.ok ? 'Not read' : '…';
    const fromLive = (value: ReactNode) => (data ? value : <span className="onu-pending">{waiting}</span>);

    const ownTabs: TabDef[] = [
        { key: 'graphs', title: 'Graphs', icon: 'reports', content: <Graphs base={base} ranges={ranges} /> },
        { key: 'ports', title: 'Ports', icon: 'fiber', count: data ? data.lan.length : undefined, content: data ? <Ports live={data} base={base} mayChange={mayChange} /> : <Waiting live={live} /> },
        { key: 'speed', title: 'Speed profiles', icon: 'activity', count: data ? data.servicePorts.length : undefined, content: data ? <ServicePorts ports={data.servicePorts} tables={trafficTables} base={base} mayChange={mayChange} /> : <Waiting live={live} /> },
        ...(mayChange ? [{ key: 'omci', title: 'Configure', icon: 'settings', hint: 'Push settings to the ONU over OMCI', content: <Configure base={base} live={live} clients={clients} /> }] : []),
        ...(olt.canRead ? [{ key: 'config', title: 'Running config', icon: 'logs', content: <RunningConfig base={base} /> }] : []),
        ...(mayChange ? [{
            key: 'manage', title: 'Manage', icon: 'shield', content: (
                <div className="onu-tab-body">
                    <Panel title="Turn off or on">
                        <p className="hint">A turned-off ONU keeps its settings on the OLT but carries no traffic.</p>
                        <div className="toolbar">
                            {off
                                ? <PostButton action={`${base}/enable`} label="Enable ONU" className="button" />
                                : <PostButton action={`${base}/disable`} label="Disable ONU" className="button" confirm={{ title: 'Turn this ONU off?', message: `${onu.description || onu.sn} loses its connection until you turn the ONU on again. Its settings stay on the OLT.`, confirmLabel: 'Turn off' }} />}
                        </div>
                    </Panel>
                    {can('danger.delete') && (
                        <Panel title="Danger zone" className="onu-danger">
                            <p className="hint">Restoring defaults wipes what the customer set on the ONU. Deleting removes it and its service ports from the OLT.</p>
                            <div className="toolbar">
                                <PostButton action={`${base}/factory-reset`} label="Restore defaults" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Put this ONU back to its factory settings?', message: 'The customer\'s own Wi-Fi name and password and anything else set on the ONU are lost. The ONU restarts, and the OLT sets its internet connection up again.', confirmLabel: 'Restore defaults', typeToConfirm: onu.sn }} />
                                <DeleteButton action={`${base}/delete`} label="Delete" title={`Remove ${onu.sn} from the OLT?`} message="The ONU and its service ports are deleted on the OLT, and its client is unlinked. The customer is offline until the ONU is added again." typeToConfirm={onu.sn} />
                            </div>
                        </Panel>
                    )}
                </div>
            ),
        }] : []),
    ];

    return (
        <AppLayout title={`ONU ${onu.sn}`}>
            <nav className="onu-crumbs" aria-label="Where this ONU is">
                <Link href="/olts">OLTs</Link><span aria-hidden="true">/</span>
                <Link href={`/olts/${olt.id}`}>{olt.name}</Link><span aria-hidden="true">/</span>
                <span>PON {frame}/{slot}/{port}</span><span aria-hidden="true">/</span>
                <span aria-current="page">ONU {onuId}</span>
            </nav>
            {can('nas.manage') && !olt.canChange && <p className="hint">Changes are turned off for this OLT, so this page only reads. To restart, turn off, rename or remove ONUs here, turn on <Link href={`/olts/${olt.id}/edit`}>Allow changes from the portal</Link>.</p>}

            <section className={`onu-hero ${status === 'online' ? 'is-online' : status === 'off' ? 'is-off' : status === 'offline' ? 'is-offline' : ''}`.trim()} aria-label="ONU at a glance">
                <div className="onu-hero-top">
                    <div className="onu-identity">
                        <RenameTitle name={onu.description} sn={onu.sn} base={base} mayChange={mayChange} />
                        <p>
                            <span className="mono">{onu.sn}</span>
                            {onu.model && <><span aria-hidden="true">·</span>{onu.model}</>}
                            <span aria-hidden="true">·</span><span className="mono">{onu.position}</span>
                        </p>
                        <div className="onu-status-line">
                            {status === 'off' ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={status} />}
                            {since !== null && status === onu.status && <span>{status === 'online' ? 'for' : 'since'} <strong>{duration(since)}</strong></span>}
                            {onu.distance !== null && <span><strong>{onu.distance >= 1000 ? `${(onu.distance / 1000).toFixed(2)} km` : `${onu.distance} m`}</strong> of fibre</span>}
                            {disconnections.day > 0 && <Badge tone="warning">{disconnections.day} disconnection{disconnections.day === 1 ? '' : 's'} in 24 h</Badge>}
                        </div>
                    </div>
                    <div className="onu-hero-actions">
                        {olt.canRead && (
                            <>
                                <button type="button" className="button" disabled={live.busy} onClick={() => void live.read()}><Icon name="refresh" />{live.busy ? 'Asking the OLT…' : 'Get status'}</button>
                                <button type="button" className={`button onu-live-toggle${live.auto ? ' on' : ''}`} aria-pressed={live.auto} onClick={() => live.setAuto(!live.auto)} title="Ask the OLT again every 10 seconds"><span className="onu-live-dot" aria-hidden="true" />Live</button>
                            </>
                        )}
                        {mayChange && <PostButton action={`${base}/reboot`} label="Reboot" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Restart this ONU?', message: `${onu.description || onu.sn} loses its connection for a minute or two.`, confirmLabel: 'Restart' }} />}
                    </div>
                </div>

                <div className="onu-hero-body">
                    <div className="onu-device">
                        <DeviceArt online={status === 'online'} off={status === 'off'} lan={data ? data.lan : null} />
                        {live.answer && !live.answer.ok && <p className="onu-device-note" role="alert">{live.answer.error}</p>}
                    </div>
                    <div className="onu-meters">
                        <SignalMeter label="ONU Rx · 1490 nm" dbm={rx} weak={olt.rxWarning} veryWeak={olt.rxCritical} note={data?.optical.tx ? `Transmits ${data.optical.tx} dBm` : undefined} />
                        <SignalMeter label="OLT Rx · 1310 nm" dbm={oltRx} weak={olt.rxWarning} veryWeak={olt.rxCritical} />
                        <div className="onu-meter onu-traffic">
                            <div className="onu-meter-head"><span>Traffic now</span>{live.auto && <small>Live</small>}</div>
                            <div className="onu-traffic-rows">
                                <div><span aria-hidden="true">↓</span><strong>{fromLive(rate(data?.traffic.down))}</strong><small>Download</small></div>
                                <div><span aria-hidden="true">↑</span><strong>{fromLive(rate(data?.traffic.up))}</strong><small>Upload</small></div>
                            </div>
                        </div>
                    </div>
                </div>

                <StatusStrip timeline={timeline} disconnections={disconnections} />
            </section>

            <div className="onu-facts-grid">
                <Facts title="Location" icon="fiber">
                    <Fact label="OLT"><Link href={`/olts/${olt.id}`}>{olt.name}</Link></Fact>
                    <Fact label="Frame / board / port" mono>{frame} / {slot} / {port}</Fact>
                    <Fact label="ONU ID" mono>{onuId}</Fact>
                    <Fact label="Distance">{onu.distance === null ? '-' : `${onu.distance} m`}</Fact>
                    <Fact label="Read from the OLT"><Time value={onu.seen} /></Fact>
                </Facts>
                <Facts title="Customer" icon="user" aside={clients.length > 1 ? <Badge>{clients.length} clients</Badge> : undefined}>
                    {clients.length ? clients.map((c) => (
                        <div key={c.username} className="onu-client">
                            <dt>Client</dt>
                            <dd><Link href={`/clients/${encodeURIComponent(c.username)}/edit?tab=onu`}>{c.name || c.username}</Link>{c.name && <small className="mono"> {c.username}</small>}</dd>
                            {c.address && <><dt>Address</dt><dd>{c.address}</dd></>}
                            {c.phone && <><dt>Contact</dt><dd><a href={`tel:${c.phone.replace(/[^\d+]/g, '')}`} className="mono">{c.phone}</a></dd></>}
                        </div>
                    )) : <Fact label="Client"><span className="onu-pending">Not linked to a client</span></Fact>}
                </Facts>
                <Facts title="Device" icon="server">
                    <Fact label="Serial number" mono>{onu.sn}</Fact>
                    <Fact label="ONU type">{data?.software.model || onu.model || '-'}</Fact>
                    <Fact label="Software">{fromLive(dash(data?.software.version))}</Fact>
                    <Fact label="MAC" mono>{fromLive(dash(data?.software.mac))}</Fact>
                    <Fact label="Line / service profile">{dash(onu.profile)} / {dash(data?.state.serviceProfile || onu.serviceProfile)}</Fact>
                    <Fact label="CPU / memory">{fromLive(`${dash(data?.state.cpu)} / ${dash(data?.state.memory)}`)}</Fact>
                    <Fact label="Temperature">{data ? `${dash(data.state.temperature)} / optics ${dash(data.optical.temperature, ' °C')}` : onu.temperature !== null ? `${onu.temperature} °C` : '-'}</Fact>
                    <Fact label="Running since restart">{fromLive(dash(data?.state.uptime))}</Fact>
                </Facts>
                <Facts title="Service" icon="signal">
                    <Fact label="ONU mode">{fromLive(data?.wans.length ? (routed ? `Routing, WAN VLAN ${routed.vlan || '-'}` : 'Bridging') : data?.state.run === 'online' ? 'Bridging' : '-')}</Fact>
                    <Fact label="WAN setup">{fromLive(routed ? <>{routed.access || '-'} <span className="mono">{routed.ip}</span>{routed.status && <> <Badge tone={routed.status.toLowerCase() === 'connected' ? 'success' : 'warning'}>{routed.status}</Badge></>}</> : '-')}</Fact>
                    <Fact label="Attached VLANs">{fromLive(vlans.length ? vlans.join(', ') : '-')}</Fact>
                    <Fact label="Config from the OLT (OMCI)">{fromLive(`${dash(data?.state.config)}${data?.state.match ? `, profile ${data.state.match}` : ''}`)}</Fact>
                    <Fact label="Last came online"><Time value={onu.lastUp} /></Fact>
                    <Fact label="Last went offline"><Time value={onu.lastDown} />{(data?.state.downCause || onu.downCause) ? <small> {data?.state.downCause || onu.downCause}</small> : null}</Fact>
                </Facts>
            </div>

            <Tabs tabs={ownTabs} label="ONU details" className="tabs onu-tabs" />
        </AppLayout>
    );
}
