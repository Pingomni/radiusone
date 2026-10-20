import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { LivePanel } from '@/components/LivePanel';
import { Gauges, HistorySection, Kv, PollButton, ToneValue, type Gauge, type History } from '@/components/monitoring';
import { DataTable } from '@/components/table';
import { Badge, Empty, HealthItem, Panel, Tag, type TagData } from '@/components/ui';
import Icon from '@/components/Icon';
import AppLayout from '@/layouts/AppLayout';

type Interface = { name: string; alias: string; status: TagData; speed: string; in: string; out: string; totals: string; errors: number };
type Snapshot =
    | { status: 'unavailable'; message: string }
    | { status: 'off'; message: string; setup: string | null }
    | { status: 'down'; target: string; error: string; fresh: number }
    | {
        status: 'ok';
        gauges: Gauge[];
        system: [string, string][];
        description: string;
        sensors: { label: string; value: string; tone: string | null }[];
        interfaces: Interface[];
        hiddenInterfaces: number;
        warnings: string[];
    };

function SnapshotBody({ snap }: { snap: Snapshot }) {
    if (snap.status === 'unavailable') return <Empty>{snap.message}</Empty>;
    if (snap.status === 'off') return <Empty>{snap.message} {snap.setup && <Link className="button" href={snap.setup}>Set up SNMP</Link>}</Empty>;
    if (snap.status === 'down') {
        return (
            <div className="nas-monitor-down" role="alert">
                <strong><Badge tone="danger">unreachable</Badge> No SNMP answer from {snap.target}</strong>
                <p>{snap.error}</p>
                <p className="hint">Fresh RADIUS accounting sessions on this NAS: {snap.fresh}. Polling retries automatically.</p>
            </div>
        );
    }
    return (
        <>
            <Gauges gauges={snap.gauges} />
            <div className="nas-monitor-grid">
                <section>
                    <h3>System</h3>
                    <div className="nas-monitor-system">
                        {snap.system.map(([label, value]) => <Kv key={label} label={label}><strong className={label === 'Polled' ? 'mono' : undefined}>{value}</strong></Kv>)}
                        <p className="nas-sysdescr" title="sysDescr">{snap.description}</p>
                    </div>
                </section>
                <section>
                    <h3>Health</h3>
                    {snap.sensors.length
                        ? snap.sensors.map((s) => <Kv key={s.label} label={s.label}><ToneValue value={s.value} tone={s.tone} /></Kv>)
                        : <p className="hint">The device does not publish health sensors over SNMP.</p>}
                </section>
            </div>
            <section className="nas-monitor-interfaces">
                <h3>
                    Interfaces{' '}
                    <small className="hint">
                        Rates are averaged between refreshes{snap.hiddenInterfaces ? `; ${snap.hiddenInterfaces} more not shown` : ''}. PPP subscriber interfaces are counted above, not listed.
                    </small>
                </h3>
                <DataTable
                    rows={snap.interfaces}
                    rowKey={(i) => i.name}
                    columns={[
                        { header: 'Interface', cell: (i) => <><strong>{i.name}</strong>{i.alias && <span className="list-subline">{i.alias}</span>}</> },
                        { header: 'Status', cell: (i) => <Tag tag={i.status} /> },
                        { header: 'Speed', cell: (i) => i.speed },
                        { header: 'In', cell: (i) => <span className="mono">{i.in}</span> },
                        { header: 'Out', cell: (i) => <span className="mono">{i.out}</span> },
                        { header: 'Total in / out', cell: (i) => <span className="mono">{i.totals}</span> },
                        { header: 'Errors', cell: (i) => (i.errors > 0 ? <Badge tone="warning">{i.errors}</Badge> : '0') },
                    ]}
                />
            </section>
            {snap.warnings.length > 0 && <p className="hint nas-monitor-warnings">Some MIBs did not answer: {snap.warnings.join('; ')}</p>}
        </>
    );
}

type Cell = string | { text: string; tone: string };
type TableData = { columns: string[]; rows: Cell[][]; truncated: boolean; empty: string; note: string } | { error: string };
const MONO = /address|MAC|peer|neighbour|router id/i;

/** Routing and layer-2 tables, read live over SNMP when a tab is opened. */
function NetworkTables({ nasId, tables }: { nasId: number; tables: Record<string, string> }) {
    const [table, setTable] = useState(Object.keys(tables)[0]);
    const [data, setData] = useState<TableData | null>(null);
    const [loading, setLoading] = useState(false);
    const [filter, setFilter] = useState('');

    const load = useCallback(async (key: string) => {
        setLoading(true);
        try {
            const response = await fetch(`/nas/${nasId}/monitor/tables/${key}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Live-Fragment': '1' } });
            setData(response.ok ? ((await response.json()) as TableData) : { error: `The table could not be read (HTTP ${response.status}).` });
        } catch {
            setData({ error: 'The table could not be read. Check the connection and try again.' });
        } finally {
            setLoading(false);
        }
    }, [nasId]);
    useEffect(() => { setData(null); setFilter(''); void load(table); }, [table, load]);

    const text = (c: Cell) => (typeof c === 'string' ? c : c.text);
    const rows = data && 'rows' in data && filter.trim()
        ? data.rows.filter((r) => r.some((c) => text(c).toLowerCase().includes(filter.trim().toLowerCase())))
        : data && 'rows' in data ? data.rows : [];
    return (
        <Panel title="Network tables">
            <div className="nas-tables-bar">
                <div className="segmented" role="tablist" aria-label="Network tables">
                    {Object.entries(tables).map(([key, label]) => (
                        <button key={key} type="button" role="tab" aria-selected={table === key} className={table === key ? 'active' : undefined} onClick={() => setTable(key)}>{label}</button>
                    ))}
                </div>
                <input type="search" aria-label="Filter rows" placeholder="Filter" value={filter} onChange={(e) => setFilter(e.target.value)} />
                <button type="button" onClick={() => void load(table)} disabled={loading}>{loading ? 'Reading…' : 'Refresh'}</button>
            </div>
            {!data ? <p className="hint">Reading {tables[table]} from the device…</p>
                : 'error' in data ? <p className="field-error">{data.error}</p>
                : (
                    <>
                        <div className="nas-tables-table">
                        <DataTable
                            rows={rows}
                            exportRows
                            emptyText={filter ? 'No rows match the filter.' : data.empty}
                            columns={data.columns.map((header, i) => ({
                                header,
                                cell: (r: Cell[]) => {
                                    const c = r[i];
                                    if (typeof c !== 'string') return c.tone ? <Badge tone={c.tone}>{c.text}</Badge> : c.text;
                                    return MONO.test(header) ? <span className="mono">{c}</span> : c;
                                },
                            }))}
                        />
                        </div>
                        <p className="hint">
                            {data.rows.length} row{data.rows.length === 1 ? '' : 's'}{filter && rows.length !== data.rows.length ? `, ${rows.length} shown` : ''}, read live over SNMP.
                            {data.truncated && ' Only the first 2,000 are shown.'}{data.note && ` ${data.note}`}
                        </p>
                    </>
                )}
        </Panel>
    );
}

/** No SNMP yet: what is known anyway, and one clear way to set it up. */
function SetupSnmp({ nas, basics, setup, canManage }: { nas: { id: number; name: string; ip: string }; basics: { label: string; value: string; tone: string }[]; setup: string | null; canManage: boolean }) {
    return (
        <Panel title={`${nas.name} ${nas.ip}`}>
            {basics.length > 0 && <div className="health-grid nas-basics">{basics.map((i) => <HealthItem key={i.label} {...i} />)}</div>}
            <div className="snmp-setup">
                <span className="snmp-setup-icon"><Icon name="signal" /></span>
                <div>
                    <h3>Set up SNMP to monitor this NAS</h3>
                    <p>
                        SNMP adds CPU, memory and temperature, interface traffic, history graphs, and the network tables
                        (IP addresses, ARP, MAC table, BGP and OSPF). It is read-only: give the router a v2c community or an SNMPv3 user
                        that can only read, and allow this server to reach it on UDP 161.
                    </p>
                    {setup
                        ? <Link className="button primary" href={setup}><Icon name="settings" />Set up SNMP</Link>
                        : <p className="hint">{canManage ? 'Apply the SNMP migration first.' : 'Ask an administrator to set up SNMP for this NAS.'}</p>}
                </div>
            </div>
        </Panel>
    );
}

export default function NasMonitor({ nas, snapshot, history, vendorLabel, oids, canManage, tables, basics }: {
    nas: { id: number; name: string; ip: string };
    snapshot: Snapshot;
    history: History;
    vendorLabel: string;
    oids: { group: string; metric: string; oid: string; notes: string }[];
    canManage: boolean;
    tables: Record<string, string> | null;
    basics: { label: string; value: string; tone: string }[] | null;
}) {
    const noSnmp = snapshot.status === 'off';
    // The network tables are read live from the device, so they are hidden while it does not answer SNMP.
    const [down, setDown] = useState(snapshot.status === 'down');
    useEffect(() => setDown(snapshot.status === 'down'), [snapshot]);
    // A NAS whose SNMP was switched off may still have recorded history worth showing.
    const recorded = history.available && [history.health?.data, history.temperature?.data, history.traffic.data].some((d) => d !== undefined && 'chart' in d);
    return (
        <AppLayout title="NAS Monitor">
            <div className="toolbar">
                <Link className="button" href="/monitoring">Back to monitoring</Link>
                <Link className="button" href={`/nas/${nas.id}/edit`}>{canManage ? 'Edit NAS and SNMP' : 'NAS details'}</Link>
                {!noSnmp && <PollButton target={String(nas.id)} back={`/nas/${nas.id}/monitor`} label="Poll this NAS now" />}
            </div>
            {noSnmp ? (
                <>
                    <SetupSnmp nas={nas} basics={basics ?? []} setup={snapshot.status === 'off' ? snapshot.setup : null} canManage={canManage} />
                    {recorded && <HistorySection history={history} pageUrl={`/nas/${nas.id}/monitor`} />}
                </>
            ) : (
                <>
                    <LivePanel title={`${nas.name} ${nas.ip}`} url={`/nas/${nas.id}/monitor/live`} interval={15} initial={snapshot} className="nas-monitor" render={(s) => <SnapshotBody snap={s} />} onData={(s) => setDown(s.status === 'down')} />
                    {tables && !down && <NetworkTables nasId={nas.id} tables={tables} />}
                    <HistorySection history={history} pageUrl={`/nas/${nas.id}/monitor`} />
                </>
            )}
            <Panel title="SNMP OID reference">
                <details className="nas-oid-reference">
                    <summary>OIDs polled for {vendorLabel}</summary>
                    <DataTable
                        rows={oids}
                        columns={[
                            { header: 'Group', cell: (r) => r.group },
                            { header: 'Metric', cell: (r) => r.metric },
                            { header: 'OID', cell: (r) => <code className="mono">{r.oid}</code> },
                            { header: 'Notes', cell: (r) => r.notes },
                        ]}
                    />
                </details>
            </Panel>
        </AppLayout>
    );
}
