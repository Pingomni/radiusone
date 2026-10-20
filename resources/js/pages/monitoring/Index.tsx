import { Link } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { LivePanel } from '@/components/LivePanel';
import { MetricCards, type Metric } from '@/components/metrics';
import { PollButton } from '@/components/monitoring';
import { Badge, Empty, HealthItem, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Item = { label: string; value: string; tone: string };
type NasCard = { id: number; name: string; ip: string; vendor: string; state: TagData; danger: boolean; radiusDisabled: boolean; items: Item[]; readings: Item[]; setupSnmp: boolean };
type Job = { label: string; does: string; detail: string; state: string; tone: string };
type Overview = {
    metrics: Metric[];
    problems: { text: string; href: string }[];
    server: { host: string; running: string[]; down: string[]; items: Item[] };
    nas: NasCard[];
    jobs: Job[];
};

/** Secondary figures on one line: "CPU 3 % · Memory 12 % · Temp 48 °C". */
function Readings({ items }: { items: Item[] }) {
    if (!items.length) return null;
    return (
        <p className="monitor-readings">
            {items.map((i) => <span key={i.label} className={i.tone ? `tone-${i.tone}` : undefined}><small>{i.label}</small> {i.value}</span>)}
        </p>
    );
}

function OverviewBody({ data, canManage }: { data: Overview; canManage: boolean }) {
    const { server } = data;
    // The server card shows the same four-fact grid as the NAS cards; the rest is a readings line.
    const serverFacts = server.items.filter((i) => ['CPU', 'Memory', 'Disk /', 'RADIUS last 15 min'].includes(i.label));
    const serverReadings = server.items.filter((i) => !serverFacts.includes(i)).map((i) => ({ ...i, label: i.label.replace(' (1/5/15 min)', '').replace(' (all NAS)', '') }));
    return (
        <div className="monitor-body">
            <MetricCards metrics={data.metrics} />
            {data.problems.length > 0 && (
                <section className="monitor-attention" aria-label="Needs attention">
                    <h3><Icon name="signal" />Needs attention</h3>
                    <ul>{data.problems.map((p) => <li key={p.text}><Link href={p.href}>{p.text}</Link></li>)}</ul>
                </section>
            )}
            <div className="health-card-grid monitor-grid">
                <article className={`health-card monitor-card monitor-server${server.down.length ? ' tone-danger' : ''}`}>
                    <div className="health-card-head">
                        <div><h2><Link href="/monitoring/server">RADIUS server</Link></h2><p>{server.host}</p></div>
                        {server.down.length > 0 && <Badge tone="danger">{server.down.length} service{server.down.length === 1 ? '' : 's'} down</Badge>}
                    </div>
                    <p className="monitor-services">
                        {server.running.map((r) => <span key={r} className="monitor-service">{r}</span>)}
                        {server.down.map((d) => <span key={d} className="monitor-service down">{d}</span>)}
                    </p>
                    <div className="health-grid">{serverFacts.map((i) => <HealthItem key={i.label} {...i} />)}</div>
                    <Readings items={serverReadings} />
                    <div className="health-card-actions"><Link className="button" href="/monitoring/server"><Icon name="reports" />Graphs and details</Link></div>
                </article>
                {data.nas.map((nas) => (
                    <article key={nas.id} className={`health-card monitor-card${nas.danger ? ' tone-danger' : ''}`}>
                        <div className="health-card-head">
                            <div><h2><Link href={`/nas/${nas.id}/monitor`}>{nas.name}</Link></h2><p>{nas.ip} · {nas.vendor}</p></div>
                            {nas.radiusDisabled ? <Badge tone="warning">disabled</Badge> : nas.danger && <Badge tone="danger">problem</Badge>}
                        </div>
                        <div className="health-grid">{nas.items.map((i) => <HealthItem key={i.label} {...i} />)}</div>
                        <Readings items={nas.readings} />
                        <div className="health-card-actions">
                            <Link className="button" href={`/nas/${nas.id}/monitor`}><Icon name="reports" />Monitor</Link>
                            {nas.setupSnmp && <Link className="button" href={`/nas/${nas.id}/edit?tab=snmp`}>Set up SNMP</Link>}
                        </div>
                    </article>
                ))}
                {!data.nas.length && <Empty>No NAS devices yet. {canManage && <Link className="button" href="/nas/create">Add a NAS</Link>}</Empty>}
            </div>
            <section className="monitor-jobs" aria-label="Background jobs">
                <h3>Background jobs</h3>
                {data.jobs.map((job) => (
                    <div className="monitor-job" key={job.label} title={job.does}>
                        <strong>{job.label}</strong>
                        <span>{job.tone ? <Badge tone={job.tone}>{job.state}</Badge> : <span className="monitor-job-ok">{job.state}</span>}</span>
                        <small>{job.detail}</small>
                    </div>
                ))}
            </section>
        </div>
    );
}

export default function Monitoring({ overview, lastPoll }: { overview: Overview; lastPoll: { age: string; stale: boolean } | null }) {
    const can = useCan();
    return (
        <AppLayout title="Monitoring">
            <div className="toolbar">
                <PollButton target="all" back="/monitoring" label="Poll all devices now" />
                {lastPoll === null
                    ? <span className="hint">No poll recorded yet.</span>
                    : lastPoll.stale
                        ? <span className="field-warning">The scheduled poller last ran {lastPoll.age} ago. Check <code>/etc/cron.d/freeradius-web-snmp</code>.</span>
                        : <span className="hint">Polled automatically every minute; last run {lastPoll.age} ago.</span>}
            </div>
            <LivePanel
                title="Status"
                url="/monitoring/live"
                interval={30}
                initial={overview}
                className="monitoring-panel"
                headerLink={can('nas.manage') ? <Link href="/nas">NAS devices</Link> : undefined}
                render={(data) => <OverviewBody data={data} canManage={can('nas.manage')} />}
            />
        </AppLayout>
    );
}
