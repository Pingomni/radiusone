import { Link } from '@inertiajs/react';
import { LivePanel } from '@/components/LivePanel';
import { Gauges, HistorySection, Kv, PollButton, ToneValue, type Gauge, type History } from '@/components/monitoring';
import { Badge } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Status = {
    gauges: Gauge[];
    services: { label: string; unit: string; state: string; running: boolean }[];
    system: [string, string][];
    temperatures: { label: string; value: string; tone: string | null }[];
};

function StatusBody({ status }: { status: Status }) {
    return (
        <>
            <Gauges gauges={status.gauges} />
            <div className="nas-monitor-grid">
                <section>
                    <h3>Services</h3>
                    {status.services.map((s) => (
                        <Kv key={s.unit} label={<>{s.label} <small className="mono hint">{s.unit}</small></>}>
                            {s.running ? <strong>running</strong> : <Badge tone="danger">{s.state}</Badge>}
                        </Kv>
                    ))}
                </section>
                <section>
                    <h3>System</h3>
                    {status.system.map(([label, value]) => <Kv key={label} label={label}><strong>{value}</strong></Kv>)}
                    {status.temperatures.length
                        ? status.temperatures.map((t) => <Kv key={t.label} label={t.label}><ToneValue value={t.value} tone={t.tone} /></Kv>)
                        : <p className="hint">No temperature sensors (usual for a virtual machine).</p>}
                </section>
            </div>
        </>
    );
}

export default function Server({ host, status, history, serverId }: { host: string; status: Status; history: History; serverId: number }) {
    return (
        <AppLayout title="RADIUS Server">
            <div className="toolbar">
                <Link className="button" href="/monitoring">Back to monitoring</Link>
                <PollButton target={String(serverId)} back="/monitoring/server" label="Poll server now" />
            </div>
            <LivePanel title={`RADIUS server · ${host}`} url="/monitoring/server/live" interval={15} initial={status} className="nas-monitor" render={(s) => <StatusBody status={s} />} />
            <HistorySection history={history} pageUrl="/monitoring/server" />
        </AppLayout>
    );
}
