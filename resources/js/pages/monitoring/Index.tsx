import { Link } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { LivePanel } from '@/components/LivePanel';
import { MetricCards, type Metric } from '@/components/metrics';
import { PollButton } from '@/components/monitoring';
import { Badge, Empty, HealthItem, Tag, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Item = { label: string; value: string; tone: string };
type NasCard = { id: number; name: string; ip: string; vendor: string; state: TagData; danger: boolean; radiusDisabled: boolean; items: Item[]; setupSnmp: boolean };
type Job = { label: string; does: string; detail: string; state: string; tone: string };
type Overview = {
    metrics: Metric[];
    server: { host: string; running: string[]; down: string[]; items: Item[] };
    nas: NasCard[];
    jobs: Job[];
};

function OverviewBody({ data, canManage }: { data: Overview; canManage: boolean }) {
    const { server } = data;
    return (
        <>
            <MetricCards metrics={data.metrics} />
            <div className="health-card-grid monitor-grid">
                <article className={`health-card monitor-card monitor-server${server.down.length ? ' tone-danger' : ''}`}>
                    <div className="health-card-head">
                        <div><h2><Link href="/monitoring/server">RADIUS server</Link></h2><p>{server.host}</p></div>
                        {server.down.length
                            ? <Badge tone="danger">{server.down.length} service{server.down.length === 1 ? '' : 's'} down</Badge>
                            : <Badge>all services running</Badge>}
                    </div>
                    <div className="monitor-services">
                        {server.running.length > 0 && <span className="monitor-running">{server.running.join(' · ')}</span>}
                        {server.down.map((d) => <Badge key={d} tone="danger">{d}</Badge>)}
                    </div>
                    <div className="health-grid">{server.items.map((i) => <HealthItem key={i.label} {...i} />)}</div>
                    <div className="health-card-actions"><Link className="button" href="/monitoring/server"><Icon name="reports" />Graphs and details</Link></div>
                </article>
                {data.nas.map((nas) => (
                    <article key={nas.id} className={`health-card monitor-card${nas.danger ? ' tone-danger' : ''}`}>
                        <div className="health-card-head">
                            <div><h2><Link href={`/nas/${nas.id}/monitor`}>{nas.name}</Link></h2><p>{nas.ip} · {nas.vendor}</p></div>
                            <Badge tone={nas.state.tone ?? ''}>{nas.state.text}</Badge>
                        </div>
                        {nas.radiusDisabled && <p className="monitor-card-note">RADIUS requests from this NAS are disabled.</p>}
                        <div className="health-grid">{nas.items.map((i) => <HealthItem key={i.label} {...i} />)}</div>
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
                    <div className="monitor-job" key={job.label}>
                        <div><strong>{job.label}</strong><small>{job.does}</small></div>
                        <div className="monitor-job-state">{job.tone ? <Badge tone={job.tone}>{job.state}</Badge> : <strong>{job.state}</strong>}<small>{job.detail}</small></div>
                    </div>
                ))}
                <p className="hint">Jobs run from cron on this server. A job that is not running leaves its feature switched off; see docs/operations for how each one is enabled.</p>
            </section>
        </>
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
                title="Devices"
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
