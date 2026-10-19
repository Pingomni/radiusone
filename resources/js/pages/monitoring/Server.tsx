import { Link } from '@inertiajs/react';
import { LivePanel } from '@/components/LivePanel';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { Gauges, HistorySection, Kv, PollButton, ToneValue, type Gauge, type History } from '@/components/monitoring';
import { Badge } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Status = {
    gauges: Gauge[];
    services: { label: string; unit: string; state: string; running: boolean; uptime: string | null }[];
    system: [string, string][];
    temperatures: { label: string; value: string; tone: string | null }[];
};

/** What each restart interrupts, shown in the confirmation. */
const IMPACT: Record<string, string> = {
    freeradius: 'Authentication and accounting stop for a few seconds; NAS devices retry, so online users normally stay connected.',
    mariadb: 'The database stops for a few seconds: the portal and RADIUS authentication fail until it is back.',
    nginx: 'The portal is unreachable for a few seconds.',
};

function RestartButton({ unit, label }: { unit: string; label: string }) {
    return (
        <PostButton
            action="/monitoring/server/restart"
            data={{ unit }}
            className=""
            label={<><Icon name="refresh" />Restart</>}
            title={`Restart ${label}`}
            confirm={{
                title: `Restart ${label}?`,
                message: `${IMPACT[unit] ?? 'The portal is unreachable for a few seconds.'} The configuration is checked first; the restart starts two seconds later.`,
                confirmLabel: `Restart ${label}`,
                typeToConfirm: unit === 'mariadb' ? 'restart' : undefined,
            }}
        />
    );
}

function StatusBody({ status, restartable }: { status: Status; restartable: Record<string, string> }) {
    return (
        <>
            <Gauges gauges={status.gauges} />
            <div className="nas-monitor-grid">
                <section>
                    <h3>Services</h3>
                    {status.services.map((s) => (
                        <Kv key={s.unit} label={<>{s.label} <small className="mono hint">{s.unit}</small></>}>
                            <span className="service-state">
                                {s.running ? <strong>running{s.uptime && <small className="service-uptime" title="Time since the service last started"> · up {s.uptime}</small>}</strong> : <Badge tone="danger">{s.state}</Badge>}
                                {restartable[s.unit] && <RestartButton unit={s.unit} label={s.label} />}
                            </span>
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

export default function Server({ host, status, history, serverId, restartable }: { host: string; status: Status; history: History; serverId: number; restartable: Record<string, string> }) {
    return (
        <AppLayout title="RADIUS Server">
            <div className="toolbar">
                <Link className="button" href="/monitoring">Back to monitoring</Link>
                <PollButton target={String(serverId)} back="/monitoring/server" label="Poll server now" />
            </div>
            <LivePanel title={`RADIUS server · ${host}`} url="/monitoring/server/live" interval={15} initial={status} className="nas-monitor" render={(s) => <StatusBody status={s} restartable={restartable} />} />
            <HistorySection history={history} pageUrl="/monitoring/server" />
        </AppLayout>
    );
}
