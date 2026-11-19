import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { DeleteButton, PostButton, useConfirm } from '@/components/confirm';
import Icon from '@/components/Icon';
import { ChartOrMessage, type ChartPayload } from '@/components/LineChart';
import { Changelog, type Change } from '@/components/OltChangelog';
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
    /** The ONU's part of the OLT's configuration, and what it sets, read in the same login. */
    config?: string;
    omci?: OnuState | null;
};
type Answer = { ok: true; data: Live; age?: number; failed?: string } | { ok: false; error: string };
type TrafficTable = { id: number; cir: string; pir: string };
type OltInfo = { id: number; name: string; canRead: boolean; canChange: boolean; rxWarning: number; rxCritical: number };
type Client = { username: string; name: string; address: string; phone: string; pppoe: boolean };
type Profile = { id: number; name: string; bound: number };
type OmciWan = { mode: 'dhcp' | 'pppoe' | 'static'; vlan: number; priority: number; user: string; internet: boolean; profile: number | null };
type OmciPort = { route: boolean; native: number | null; tagged: number[]; transparent: boolean };
type OnuState = { mode: 'routing' | 'bridging' | 'none'; wans: Record<string, OmciWan>; routePolicy: number | null; ports: Record<string, OmciPort> };
type OmciOptions = { vlans: number[]; wanProfiles: Profile[]; routeProfiles: Profile[]; trafficTables: TrafficTable[]; state: OnuState };
type Segment = { from: number; to: number; state: 'online' | 'offline' | 'none' };
type Timeline = { from: number; to: number; segments: Segment[] };
type LiveState = { answer: Answer | null; busy: boolean; auto: boolean; setAuto: (on: boolean) => void; read: (fresh?: boolean) => Promise<void>; enabled: boolean };
type Choices = { ok: true; data: OmciOptions } | { ok: false; error: string };

/**
 * What this browser tab has read, by ONU. A page opened again, or rebuilt after an action, starts from here at once and
 * is brought up to date in the background, instead of waiting on the OLT with nothing to show.
 */
const kept: { live: Map<string, Answer>; choices: Map<string, Choices>; graphs: Map<string, ChartPayload> } = { live: new Map(), choices: new Map(), graphs: new Map() };

const dash = (value: string | number | null | undefined, unit = '') => (value === null || value === undefined || value === '' ? '-' : `${value}${unit}`);
/** A traffic table's speed as operators say it: 100M, 1G, Unlimited. The OLT counts 1024 kbit/s to the megabit. */
const speedName = (kbps: string | undefined) => {
    if (kbps === undefined) return '-';
    if (kbps === 'off') return 'Unlimited';
    const mbit = Number(kbps) / 1024;
    if (mbit >= 1000) return `${Number((mbit / 1024).toFixed(1))}G`;
    return mbit >= 1 ? `${Math.round(mbit)}M` : `${kbps}k`;
};
const rate = (kbps: number | null | undefined) => (kbps === null || kbps === undefined ? '-' : kbps >= 1000 ? `${(kbps / 1000).toFixed(1)} Mbit/s` : `${kbps} kbit/s`);
const num = (value: string | undefined) => (value && !Number.isNaN(Number(value)) ? Number(value) : null);
const clock = (seconds: number) => new Date(seconds * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

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

/** One read of the OLT shared by the whole page, repeated while the page is in view. */
function useLive(base: string, enabled: boolean): LiveState {
    const [answer, setAnswerState] = useState<Answer | null>(() => kept.live.get(base) ?? null);
    const [busy, setBusy] = useState(false);
    const [auto, setAuto] = useState(false);
    // A failed read does not wipe a good one: the page keeps what it had and says the last read failed.
    const setAnswer = useCallback((next: Answer) => {
        if (next.ok) kept.live.set(base, next);
        setAnswerState((before) => (next.ok || !before?.ok ? next : { ...before, failed: next.error }));
    }, [base]);
    // The first read may come from the last 30 seconds; Get status and Live always ask the OLT.
    const read = useCallback(async (fresh = true) => {
        setBusy(true);
        try {
            const first = await getJson<Answer>(`${base}/live${fresh ? '?fresh=1' : '?quick=1'}`);
            setAnswer(first);
            // Opening the page shows the last read at once; if that is not from just now, the OLT is asked straight after.
            if (!fresh && first.ok && (first.age ?? 0) > 30) setAnswer(await getJson<Answer>(`${base}/live?fresh=1`));
        } catch {
            setAnswer({ ok: false, error: 'The portal could not be reached, or your session ended. Reload the page and try again.' });
        } finally {
            setBusy(false);
        }
    }, [base, setAnswer]);
    useEffect(() => { if (enabled) void read(false); }, [enabled, read]);
    // The page keeps itself up to date: every 30 seconds, or every 10 with Live on. A check each second finds when the
    // next read is due, so a page that was in the background reads again as soon as it is looked at, and an answer
    // that is still on its way is never asked for twice.
    const busyNow = useRef(false);
    busyNow.current = busy;
    const lastRead = useRef(Date.now());
    useEffect(() => { if (!busy) lastRead.current = Date.now(); }, [busy]);
    useEffect(() => {
        if (!enabled) return undefined;
        const every = auto ? 10000 : 30000;
        const timer = window.setInterval(() => {
            if (!document.hidden && !busyNow.current && Date.now() - lastRead.current >= every) void read();
        }, 1000);
        return () => window.clearInterval(timer);
    }, [enabled, auto, read]);
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
    const [payload, setPayload] = useState<ChartPayload | null>(() => kept.graphs.get(url) ?? null);
    useEffect(() => {
        let alive = true;
        setPayload(kept.graphs.get(url) ?? null);
        const load = () => getJson<ChartPayload>(url).then((data) => { kept.graphs.set(url, data); if (alive) setPayload(data); }).catch(() => undefined);
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

/** The ONU's part of the OLT's configuration. It comes with the page's own read, so the tab opens at once. */
function RunningConfig({ live }: { live: LiveState }) {
    const config = live.answer?.ok ? live.answer.data.config ?? '' : '';
    return (
        <Panel title="Running config on the OLT" actions={<button type="button" className="button" disabled={live.busy} onClick={() => void live.read()}><Icon name="refresh" />{live.busy ? 'Asking the OLT…' : 'Read again'}</button>}>
            {config !== '' ? <pre className="mono onu-config" tabIndex={0}>{config}</pre> : <p className="hint" role="status">{live.answer ? 'The OLT did not print a configuration for this ONU.' : 'Asking the OLT. This takes a few seconds.'}</p>}
        </Panel>
    );
}

function ServicePorts({ ports, tables, base, mayChange, onDone }: { ports: ServicePort[]; tables: TrafficTable[]; base: string; mayChange: boolean; onDone: () => void }) {
    const [editing, setEditing] = useState<{ index: number; up: string; down: string } | null>(null);
    const byId = new Map(tables.map((t) => [t.id, t]));
    const sorted = [...tables].sort((a, b) => (a.pir === 'off' ? Infinity : Number(a.pir)) - (b.pir === 'off' ? Infinity : Number(b.pir)) || a.id - b.id);
    const options = sorted.map((t) => ({ value: String(t.id), label: `${speedName(t.pir)}  (traffic table ${t.id})` }));
    const [mbps, setMbps] = useState('');
    const [adding, setAdding] = useState(false);
    const named = (id: number | null) => (id === null ? '-' : <><strong>{speedName(byId.get(id)?.pir)}</strong> <small className="onu-pending">table {id}</small></>);
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
                    { header: 'Download', cell: (p) => named(p.down) },
                    { header: 'Upload', cell: (p) => named(p.up) },
                    { header: 'State', cell: (p) => (p.state ? <Badge tone="success">Up</Badge> : <Badge>Down</Badge>) },
                    ...(mayChange && tables.length ? [{ header: '', cell: (p: ServicePort) => <button type="button" className="button" onClick={() => setEditing({ index: p.index, up: String(p.up ?? ''), down: String(p.down ?? '') })}>Configure</button> }] : []),
                ]}
            />
            {editing && (
                <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${base}/service-ports/${editing.index}`, { up: editing.up, down: editing.down }, { preserveScroll: true, preserveState: true, onSuccess: () => { setEditing(null); onDone(); } }); }}>
                    <Select label={`Service port ${editing.index}: download`} name="down" placeholder="Choose" options={options} value={editing.down} onChange={(e) => setEditing({ ...editing, down: e.target.value })} />
                    <Select label="Upload" name="up" placeholder="Choose" options={options} value={editing.up} onChange={(e) => setEditing({ ...editing, up: e.target.value })} />
                    <button className="button primary" disabled={!editing.up || !editing.down}>Save on the OLT</button>
                    <button type="button" className="button" onClick={() => setEditing(null)}>Cancel</button>
                </form>
            )}
            {mayChange && (
                <form className="toolbar onu-add-speed" onSubmit={(e) => { e.preventDefault(); setAdding(true); router.post(`${base}/add-speed`, { mbps }, { preserveScroll: true, preserveState: true, onSuccess: () => { setMbps(''); onDone(); }, onFinish: () => setAdding(false) }); }}>
                    <Field label="Add a speed to the OLT (Mbit/s)" name="mbps" type="number" min={1} max={10000} inputMode="numeric" placeholder="100" value={mbps} onChange={(e) => setMbps(e.target.value)} hint={`Speeds on the OLT: ${[...new Set(sorted.map((t) => speedName(t.pir)))].join(', ') || 'none'}.`} />
                    <button className="button" disabled={adding || !(Number(mbps) >= 1 && Number(mbps) <= 10000)}>{adding ? 'Adding…' : 'Add speed'}</button>
                </form>
            )}
        </Panel>
    );
}

function Ports({ live, base, mayChange, onDone }: { live: Live; base: string; mayChange: boolean; onDone: () => void }) {
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
                            ? <PostButton action={`${base}/lan/${p.port}`} data={{ state: 'off' }} preserveState onSuccess={onDone} label="Turn off" confirm={{ title: `Turn LAN port ${p.port} off?`, message: 'Whatever is plugged into this port on the ONU loses its connection.', confirmLabel: 'Turn off' }} />
                            : <PostButton action={`${base}/lan/${p.port}`} data={{ state: 'on' }} preserveState onSuccess={onDone} label="Turn on" className="link" />) }] : []),
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
        router.post(`${base}/describe`, { description: value }, { preserveScroll: true, preserveState: true, onSuccess: () => setEditing(false), onFinish: () => setBusy(false) });
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
    // The running config says exactly what is set; the ONU's own report fills in the address.
    const [index, set0] = Object.entries(options.state.wans)[0] ?? ['1', null];
    const own = radius.find((c) => set0?.user && c.username === set0.user);
    const [busy, setBusy] = useState(false);
    const [f, setF] = useState(() => ({
        mode: (set0?.mode ?? (access.includes('pppoe') ? 'pppoe' : access.includes('static') ? 'static' : 'dhcp')) as WanMode,
        vlan: set0 && options.vlans.includes(set0.vlan) ? String(set0.vlan) : routed?.vlan && options.vlans.includes(Number(routed.vlan)) ? routed.vlan : '',
        priority: String(set0?.priority ?? 0), ipIndex: index, wanProfile: set0?.profile != null ? String(set0.profile) : '', routeProfile: options.state.routePolicy !== null ? String(options.state.routePolicy) : '', routeLan: true,
        source: own ? `client:${own.username}` : set0?.user ? 'typed' : radius.length ? `client:${radius[0].username}` : 'typed', user: own ? '' : set0?.user ?? '', password: '',
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
        router.post(`${base}/wan`, data, { preserveScroll: true, preserveState: true, onSuccess: () => { set('password', ''); onDone(); }, onFinish: () => setBusy(false) });
    };

    return (
        <>
            <p className="hint">The ONU routes: it holds the internet connection and the LAN ports go through it. Set by the OLT over OMCI.{routed ? <> Now: <strong>{routed.access || routed.type}</strong> on VLAN <strong>{routed.vlan || '-'}</strong>{routed.ip ? <>, <span className="mono">{routed.ip}</span></> : null}.</> : live ? ' The ONU has no routed connection now.' : ''}</p>
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
                        <Select label="Connection number (ip-index)" name="ip_index" hint={`${Object.keys(options.state.wans).length ? `The ONU has connection ${Object.keys(options.state.wans).join(', ')} now. ` : ''}Use the same number to change a connection rather than add a second one.`} value={f.ipIndex} onChange={(e) => set('ipIndex', e.target.value)} options={Array.from({ length: 8 }, (_, i) => ({ value: String(i), label: i === 1 ? '1 (as ONUs are added)' : String(i) }))} />
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
        </>
    );
}

/** Turns the ONU into a bridge: what routing it has is taken off, and its LAN ports put untagged traffic on one VLAN. */
function BridgeForm({ base, options, lanCount, onDone }: { base: string; options: OmciOptions; lanCount: number; onDone: () => void }) {
    const ask = useConfirm();
    const state = options.state;
    const natives = Object.values(state.ports).map((p) => p.native).filter((v): v is number => v !== null);
    const start = Object.values(state.wans)[0]?.vlan ?? natives[0];
    const [vlan, setVlan] = useState(start !== undefined && options.vlans.includes(start) ? String(start) : '');
    const [busy, setBusy] = useState(false);
    const wans = Object.entries(state.wans);
    const routes = Object.entries(state.ports).filter(([, p]) => p.route).map(([eth]) => eth);
    const preview = [
        ...routes.map((eth) => `ont port route <port> <onu> eth ${eth} disable`),
        ...(state.routePolicy !== null ? ['undo ont policy-route-config <port> <onu>'] : []),
        ...wans.flatMap(([i, w]) => [...(w.profile !== null ? [`undo ont wan-config <port> <onu> ip-index ${i}`] : []), ...(w.internet ? [`undo ont internet-config <port> <onu> ip-index ${i}`] : []), `undo ont ipconfig <port> <onu> ip-index ${i}`]),
        `ont port native-vlan <port> <onu> eth 1${lanCount > 1 ? `…${lanCount}` : ''} vlan ${vlan || '?'} priority 0`,
    ];
    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!(await ask({ title: 'Make this ONU a bridge?', message: wans.length ? 'Its internet connection is taken off the ONU: the customer\'s own router has to connect from now on. The internet drops until it does.' : 'Every LAN port puts untagged traffic on the VLAN you chose.', confirmLabel: 'Switch to bridging' }))) return;
        setBusy(true);
        router.post(`${base}/bridge`, { vlan }, { preserveScroll: true, preserveState: true, onSuccess: onDone, onFinish: () => setBusy(false) });
    };
    return (
        <form className="form-grid" onSubmit={(e) => void submit(e)}>
            <p className="hint">The ONU passes traffic through: the customer&apos;s own router connects behind it. Every LAN port puts untagged traffic on the VLAN.</p>
            <Select label="VLAN" name="vlan" placeholder="Choose" value={vlan} onChange={(e) => setVlan(e.target.value)} options={options.vlans.map((v) => ({ value: String(v), label: `VLAN ${v}` }))} />
            {wans.length > 0 && <p className="flash warning">This takes off connection {wans.map(([i, w]) => `${i} (${w.mode.toUpperCase()} on VLAN ${w.vlan})`).join(', ')}{routes.length ? ` and routing on LAN port ${routes.join(', ')}` : ''}.</p>}
            <details><summary>Commands the OLT will get</summary><pre className="mono onu-preview">{preview.join('\n')}</pre></details>
            <div className="form-actions"><button className="button primary" disabled={busy || !vlan}>{busy ? 'Switching over OMCI…' : state.mode === 'bridging' ? 'Set the bridge VLAN' : 'Switch to bridging'}</button></div>
        </form>
    );
}

type PortMode = 'lan' | 'access' | 'trunk' | 'transparent';
const PORT_MODES: { value: PortMode; title: string; text: string }[] = [
    { value: 'lan', title: 'LAN', text: 'Routed through the ONU\'s connection.' },
    { value: 'access', title: 'Access', text: 'Untagged, on one VLAN.' },
    { value: 'trunk', title: 'Trunk', text: 'Tagged VLANs; one untagged too makes it hybrid.' },
    { value: 'transparent', title: 'Transparent', text: 'Passes all traffic as it comes.' },
];
const portMode = (p: OmciPort | undefined): PortMode | null => (!p ? null : p.transparent ? 'transparent' : p.tagged.length ? 'trunk' : p.route ? 'lan' : p.native !== null ? 'access' : null);
const portWords = (p: OmciPort | undefined) => {
    const mode = portMode(p);
    if (!p || !mode) return 'Not set';
    if (mode === 'trunk') return `${p.native !== null ? 'Hybrid' : 'Trunk'}: tagged ${p.tagged.join(', ')}${p.native !== null ? `, untagged ${p.native}` : ''}`;
    if (mode === 'access') return `Access: VLAN ${p.native}`;
    return mode === 'lan' ? 'LAN (routed)' : 'Transparent';
};

/** Each LAN port's mode and VLANs. Only what changes is sent to the OLT. */
function EthPorts({ base, live, options, onDone }: { base: string; live: Live | null; options: OmciOptions; onDone: () => void }) {
    const ports = [...new Set([...(live?.lan.map((l) => l.port) ?? []), ...Object.keys(options.state.ports).map(Number)])].sort((a, b) => a - b);
    const [edit, setEdit] = useState<{ eth: number; mode: PortMode; native: string; tagged: number[] } | null>(null);
    const [busy, setBusy] = useState(false);
    const open = (eth: number) => {
        const now = options.state.ports[String(eth)];
        setEdit({ eth, mode: portMode(now) ?? 'lan', native: now?.native != null ? String(now.native) : '', tagged: now?.tagged ?? [] });
    };
    const ready = edit && (edit.mode === 'access' ? edit.native !== '' : edit.mode === 'trunk' ? edit.tagged.length > 0 && edit.tagged.length <= 16 : true);
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        if (!edit) return;
        setBusy(true);
        router.post(`${base}/eth/${edit.eth}`, { mode: edit.mode, native: edit.mode === 'access' || edit.mode === 'trunk' ? edit.native : '', tagged: edit.mode === 'trunk' ? edit.tagged.map(String) : [] }, { preserveScroll: true, preserveState: true, onSuccess: () => { setEdit(null); onDone(); }, onFinish: () => setBusy(false) });
    };
    const vlanOptions = options.vlans.map((v) => ({ value: String(v), label: `VLAN ${v}` }));
    return (
        <Panel title="Ethernet ports">
            <p className="hint">How each LAN port carries traffic, set by the OLT over OMCI. Turn a port off or on under Ports.</p>
            <DataTable
                rows={ports}
                rowKey={(eth) => eth}
                emptyText="Neither the ONU nor the OLT lists LAN ports for this ONU. It may be offline."
                columns={[
                    { header: 'Port', cell: (eth) => `LAN ${eth}` },
                    { header: 'Link', cell: (eth) => { const l = live?.lan.find((x) => x.port === eth); return !l ? '-' : l.on === false ? <Badge tone="warning">Turned off</Badge> : l.up ? <Badge tone="success">Up{l.speed ? ` ${l.speed}M` : ''}</Badge> : <Badge>Down</Badge>; } },
                    { header: 'Mode', className: 'wrap', cell: (eth) => portWords(options.state.ports[String(eth)]) },
                    { header: '', cell: (eth) => <button type="button" className="button" aria-expanded={edit?.eth === eth} onClick={() => (edit?.eth === eth ? setEdit(null) : open(eth))}>{edit?.eth === eth ? 'Close' : 'Configure'}</button> },
                ]}
            />
            {edit && (
                <form className="form-grid onu-port-form" onSubmit={save}>
                    <fieldset className="onu-choice onu-choice-4">
                        <legend>LAN {edit.eth}: mode</legend>
                        {PORT_MODES.map((m) => (
                            <label key={m.value} className={edit.mode === m.value ? 'on' : undefined}>
                                <input type="radio" name="port_mode" value={m.value} checked={edit.mode === m.value} onChange={() => setEdit({ ...edit, mode: m.value })} />
                                <strong>{m.title}</strong><small>{m.text}</small>
                            </label>
                        ))}
                    </fieldset>
                    {edit.mode === 'access' && <Select label="VLAN" name="native" placeholder="Choose" value={edit.native} onChange={(e) => setEdit({ ...edit, native: e.target.value })} options={vlanOptions} />}
                    {edit.mode === 'trunk' && (
                        <>
                            <fieldset className="onu-vlan-chips span2">
                                <legend>Tagged VLANs <small>({edit.tagged.length} of up to 16)</small></legend>
                                {options.vlans.map((v) => (
                                    <label key={v} className={edit.tagged.includes(v) ? 'on' : undefined}>
                                        <input type="checkbox" checked={edit.tagged.includes(v)} onChange={(e) => setEdit({ ...edit, tagged: e.target.checked ? [...edit.tagged, v].sort((a, b) => a - b) : edit.tagged.filter((x) => x !== v) })} />{v}
                                    </label>
                                ))}
                            </fieldset>
                            <Select label="Untagged VLAN (hybrid)" name="native" value={edit.native} onChange={(e) => setEdit({ ...edit, native: e.target.value })} options={[{ value: '', label: 'None: tagged only' }, ...vlanOptions]} />
                        </>
                    )}
                    <div className="form-actions">
                        <button className="button primary" disabled={busy || !ready}>{busy ? 'Saving over OMCI…' : `Save LAN ${edit.eth}`}</button>
                        <button type="button" className="button" onClick={() => setEdit(null)}>Cancel</button>
                    </div>
                </form>
            )}
        </Panel>
    );
}

/** The OLT's VLANs, profiles and speeds to choose from. Asked for once the page has its first read, before the tab is opened. */
function useChoices(base: string, ready: boolean): [Choices | null, () => void] {
    const [choices, setChoices] = useState<Choices | null>(() => kept.choices.get(base) ?? null);
    const [round, setRound] = useState(0);
    useEffect(() => {
        if (!ready || kept.choices.has(base)) return undefined;
        let alive = true;
        getJson<Choices>(`${base}/omci-options`)
            .then((answer) => { if (answer.ok) kept.choices.set(base, answer); if (alive) setChoices(answer); })
            .catch(() => { if (alive) setChoices({ ok: false, error: 'The portal could not be reached. Reload the page and try again.' }); });
        return () => { alive = false; };
    }, [base, ready, round]);
    // Asked for again after something that changes the choices, such as a new speed: the old ones stay until then.
    return [choices, () => { kept.choices.delete(base); setRound((r) => r + 1); }];
}

function Configure({ base, live, choices, clients, onDone }: { base: string; live: LiveState; choices: Choices | null; clients: Client[]; onDone: () => void }) {
    const [mode, setMode] = useState<'routing' | 'bridging' | null>(null);
    if (live.enabled && !live.answer) return <Panel><p className="hint" role="status">Reading what the ONU has now.</p></Panel>;
    if (!choices) return <Panel><p className="hint" role="status">Reading the OLT&apos;s VLANs and profiles.</p></Panel>;
    if (!choices.ok) return <Panel><p className="flash warning" role="alert">{choices.error}</p></Panel>;
    const data = live.answer?.ok ? live.answer.data : null;
    // What the ONU has set comes with the page's own read, so it is as new as everything else on the page.
    const state = data?.omci ?? choices.data.state;
    const options = { ...choices.data, state };
    const shown = mode ?? (state.mode === 'bridging' ? 'bridging' : 'routing');
    // The forms start over only when what the ONU has set really changed, not at every read.
    const stamp = JSON.stringify(state);
    const done = () => { setMode(null); onDone(); };
    const lanCount = Math.max(data?.lan.length ?? 0, ...Object.keys(state.ports).map(Number), 1);
    return (
        <div className="onu-tab-body">
            <Panel title="ONU mode" actions={<Badge tone={state.mode === 'none' ? 'warning' : 'info'}>Now: {state.mode === 'routing' ? 'Routing' : state.mode === 'bridging' ? 'Bridging' : 'Not set'}</Badge>}>
                <fieldset className="onu-choice onu-choice-2">
                    <legend>How the ONU works</legend>
                    {(['routing', 'bridging'] as const).map((m) => (
                        <label key={m} className={shown === m ? 'on' : undefined}>
                            <input type="radio" name="onu_mode" value={m} checked={shown === m} onChange={() => setMode(m)} />
                            <strong>{m === 'routing' ? 'Routing' : 'Bridging'}{state.mode === m ? ' (now)' : ''}</strong>
                            <small>{m === 'routing' ? 'The ONU holds the internet connection: DHCP, PPPoE or a fixed address.' : 'The ONU passes traffic through to the customer\'s own router.'}</small>
                        </label>
                    ))}
                </fieldset>
                {shown === 'routing'
                    ? <WanForm key={stamp} base={base} live={data} clients={clients} options={options} onDone={done} />
                    : <BridgeForm key={stamp} base={base} options={options} lanCount={lanCount} onDone={done} />}
            </Panel>
            <EthPorts key={stamp} base={base} live={data} options={options} onDone={done} />
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

export default function OnuPage({ olt, onu, clients, disconnections, timeline, since, trafficTables, ranges, history, addedAt }: {
    olt: OltInfo; onu: OnuRow; clients: Client[]; disconnections: { day: number; week: number }; timeline: Timeline; since: number | null; trafficTables: TrafficTable[]; ranges: Record<string, string>; history: Change[]; addedAt: string | null;
}) {
    const can = useCan();
    const base = `/olts/${olt.id}/onus/${onu.id}`;
    const mayChange = can('nas.manage') && olt.canChange;
    const off = onu.active === false;
    const sections = useRef<HTMLDivElement>(null);
    const live = useLive(base, olt.canRead);
    const data = live.answer?.ok ? live.answer.data : null;
    const [choices, rereadChoices] = useChoices(base, mayChange && data !== null);
    // After a change: the OLT is asked again, while the page keeps showing what it has.
    const reread = () => { void live.read(); };
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
        { key: 'ports', title: 'Ports', icon: 'fiber', count: data ? data.lan.length : undefined, content: data ? <Ports live={data} base={base} mayChange={mayChange} onDone={reread} /> : <Waiting live={live} /> },
        { key: 'speed', title: 'Speed profiles', icon: 'activity', count: data ? data.servicePorts.length : undefined, content: data ? <ServicePorts ports={data.servicePorts} tables={choices?.ok ? choices.data.trafficTables : trafficTables} base={base} mayChange={mayChange} onDone={() => { rereadChoices(); reread(); }} /> : <Waiting live={live} /> },
        ...(mayChange ? [{ key: 'omci', title: 'Configure', icon: 'settings', hint: 'Push settings to the ONU over OMCI', content: <Configure base={base} live={live} choices={choices} clients={clients} onDone={reread} /> }] : []),
        ...(olt.canRead ? [{ key: 'config', title: 'Running config', icon: 'logs', content: <RunningConfig live={live} /> }] : []),
        { key: 'history', title: 'History', icon: 'activity', count: history.length, content: <Changelog rows={history} oltId={olt.id} forOnu title={`History of ${onu.sn}`} /> },
        ...(mayChange ? [{
            key: 'manage', title: 'Manage', icon: 'shield', content: (
                <div className="onu-tab-body">
                    <Panel title="Turn off or on">
                        <p className="hint">A turned-off ONU keeps its settings on the OLT but carries no traffic.</p>
                        <div className="toolbar">
                            {off
                                ? <PostButton action={`${base}/enable`} preserveState onSuccess={reread} label="Enable ONU" className="button" />
                                : <PostButton action={`${base}/disable`} preserveState onSuccess={reread} label="Disable ONU" className="button" confirm={{ title: 'Turn this ONU off?', message: `${onu.description || onu.sn} loses its connection until you turn the ONU on again. Its settings stay on the OLT.`, confirmLabel: 'Turn off' }} />}
                        </div>
                    </Panel>
                    {can('danger.delete') && (
                        <Panel title="Danger zone" className="onu-danger">
                            <p className="hint">Restoring defaults wipes what the customer set on the ONU. Deleting removes it and its service ports from the OLT.</p>
                            <div className="toolbar">
                                <PostButton action={`${base}/factory-reset`} preserveState onSuccess={reread} label="Restore defaults" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Put this ONU back to its factory settings?', message: 'The customer\'s own Wi-Fi name and password and anything else set on the ONU are lost. The ONU restarts, and the OLT sets its internet connection up again.', confirmLabel: 'Restore defaults', typeToConfirm: onu.sn }} />
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
                            {live.answer?.ok && (live.answer.age ?? 0) > 0 && <span className="onu-pending">read {duration(live.answer.age ?? 0)} ago{live.busy ? ', asking again…' : ''}</span>}
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
                        {mayChange && can('danger.delete') && <Link className="button" href={`/olts/${olt.id}?tab=cli&at=${frame}/${slot}`} title={`Open the OLT's terminal at interface gpon ${frame}/${slot}`}><Icon name="terminal" />Terminal</Link>}
                        {mayChange && <PostButton action={`${base}/reboot`} preserveState onSuccess={reread} label="Reboot" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Restart this ONU?', message: `${onu.description || onu.sn} loses its connection for a minute or two.`, confirmLabel: 'Restart' }} />}
                    </div>
                </div>

                <div className="onu-hero-body">
                    <div className="onu-device">
                        <DeviceArt online={status === 'online'} off={status === 'off'} lan={data ? data.lan : null} />
                        {live.answer && !live.answer.ok && <p className="onu-device-note" role="alert">{live.answer.error}</p>}
                        {live.answer?.ok && live.answer.failed && <p className="onu-device-note" role="alert">The last read failed, so this is from the read before: {live.answer.failed}</p>}
                    </div>
                    <div className="onu-meters">
                        <SignalMeter label="ONU Rx · 1490 nm" dbm={rx} weak={olt.rxWarning} veryWeak={olt.rxCritical} note={data?.optical.tx ? `Transmits ${data.optical.tx} dBm` : undefined} />
                        <SignalMeter label="OLT Rx · 1310 nm" dbm={oltRx} weak={olt.rxWarning} veryWeak={olt.rxCritical} />
                        <div className="onu-meter onu-traffic">
                            <div className="onu-meter-head"><span>Traffic</span><small>last 5 minutes</small></div>
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
                <div className="onu-facts-stack">
                <Facts title="Location" icon="fiber">
                    <Fact label="OLT"><Link href={`/olts/${olt.id}`}>{olt.name}</Link></Fact>
                    <Fact label="Frame / board / port" mono>{frame} / {slot} / {port}</Fact>
                    <Fact label="ONU ID" mono>{onuId}</Fact>
                    <Fact label="Distance">{onu.distance === null ? '-' : `${onu.distance} m`}</Fact>
                    <Fact label="Added to the OLT">{addedAt ? <Time value={addedAt} /> : <span className="onu-pending">Before the portal kept a history</span>}</Fact>
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
                </div>
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

            {/* Choosing a section brings it to the top of the window, so the whole section shows instead of its first lines. */}
            <div ref={sections} className="onu-sections">
                <Tabs tabs={ownTabs} label="ONU details" className="tabs onu-tabs" onChange={() => window.requestAnimationFrame(() => sections.current?.scrollIntoView({ block: 'start', behavior: 'smooth' }))} />
            </div>
        </AppLayout>
    );
}
