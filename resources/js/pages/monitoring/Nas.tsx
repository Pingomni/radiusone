import { Link } from '@inertiajs/react';
import { LivePanel } from '@/components/LivePanel';
import { Gauges, HistorySection, Kv, PollButton, ToneValue, type Gauge, type History } from '@/components/monitoring';
import { DataTable } from '@/components/table';
import { Badge, Empty, Panel, Tag, type TagData } from '@/components/ui';
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

export default function NasMonitor({ nas, snapshot, history, vendorLabel, oids, canManage }: {
    nas: { id: number; name: string; ip: string };
    snapshot: Snapshot;
    history: History;
    vendorLabel: string;
    oids: { group: string; metric: string; oid: string; notes: string }[];
    canManage: boolean;
}) {
    return (
        <AppLayout title="NAS Monitor">
            <div className="toolbar">
                <Link className="button" href="/monitoring">Back to monitoring</Link>
                <Link className="button" href={`/nas/${nas.id}/edit`}>{canManage ? 'Edit NAS and SNMP' : 'NAS details'}</Link>
                <PollButton target={String(nas.id)} back={`/nas/${nas.id}/monitor`} label="Poll this NAS now" />
            </div>
            <LivePanel title={`${nas.name} ${nas.ip}`} url={`/nas/${nas.id}/monitor/live`} interval={15} initial={snapshot} className="nas-monitor" render={(s) => <SnapshotBody snap={s} />} />
            <HistorySection history={history} pageUrl={`/nas/${nas.id}/monitor`} />
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
