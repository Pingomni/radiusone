import { Link } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { LivePanel } from '@/components/LivePanel';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable } from '@/components/table';
import { Badge, Empty, Panel, Tags, Time, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan, useShared } from '@/lib/auth';

type NasCard = { id: number; name: string; address: string; href: string | null; tags: TagData[] };
type Reject = { username: string; reply: string; authdate: string };
type TopUser = { username: string; down: string; up: string; total: string };

function NasCards({ cards }: { cards: NasCard[] }) {
    if (!cards.length) return <Empty>No NAS devices configured.</Empty>;
    return (
        <div className="nas-status-grid">
            {cards.map((card) => (
                <div className="nas-status-card" key={card.id}>
                    <div>
                        <strong>{card.href ? <Link href={card.href}>{card.name}</Link> : card.name}</strong>
                        <span className="mono">{card.address}</span>
                    </div>
                    <div className="nas-badges"><Tags tags={card.tags} /></div>
                </div>
            ))}
        </div>
    );
}

function Rejects({ rows, linkClients, timezone }: { rows: Reject[]; linkClients: boolean; timezone: string }) {
    if (!rows.length) return <Empty>No authentication rejects recorded.</Empty>;
    return (
        <DataTable
            rows={rows}
            columns={[
                { header: 'Time', cell: (r) => <Time value={r.authdate} timezone={timezone} /> },
                { header: 'Username', cell: (r) => (linkClients ? <Link href={`/clients?q=${encodeURIComponent(r.username)}`}>{r.username}</Link> : r.username) },
                { header: 'Reply', cell: (r) => <Badge tone="danger">{r.reply}</Badge> },
            ]}
        />
    );
}

export default function Dashboard({ kpis, nas, rejects, topUsers }: { kpis: Metric[]; nas: NasCard[]; rejects: Reject[] | null; topUsers: TopUser[] }) {
    const can = useCan();
    const { clock } = useShared();
    return (
        <AppLayout title="Dashboard">
            <div className="workspace-intro">
                <div>
                    <h2>Network overview</h2>
                    <p>Clients, network access and usage at a glance.</p>
                </div>
                <div className="workspace-actions">
                    {can('clients.manage') && <Link className="button primary" href="/clients/create"><Icon name="plus" />New client</Link>}
                    {can('billing.view') && <Link className="button" href="/billing?status=overdue">Review overdue invoices</Link>}
                </div>
            </div>
            <LivePanel title="Key figures" url="/fragments/dashboard-kpis" interval={60} initial={kpis} className="live-bare" render={(m) => <MetricCards metrics={m} />} />
            <div className="dashboard-grid">
                <LivePanel
                    title="NAS status"
                    url="/fragments/dashboard-nas"
                    interval={30}
                    initial={nas}
                    headerLink={can('nas.view') ? <Link href="/monitoring">Monitoring</Link> : undefined}
                    render={(cards) => <NasCards cards={cards} />}
                />
                {rejects !== null && (
                    <LivePanel
                        title="Recent authentication rejects"
                        url="/fragments/dashboard-rejects"
                        interval={30}
                        initial={rejects}
                        headerLink={<Link href="/logs?reply=reject">All rejects</Link>}
                        render={(rows) => <Rejects rows={rows} linkClients={can('clients.view')} timezone={clock.timezone} />}
                    />
                )}
                <Panel title="Top users this month (sessions started this month)">
                    <DataTable
                        rows={topUsers}
                        columns={[
                            { header: 'Username', cell: (r) => (can('clients.view') ? <Link href={`/clients/${encodeURIComponent(r.username)}/edit`}>{r.username}</Link> : r.username) },
                            { header: 'Download', cell: (r) => r.down },
                            { header: 'Upload', cell: (r) => r.up },
                            { header: 'Total', cell: (r) => r.total },
                        ]}
                    />
                </Panel>
            </div>
        </AppLayout>
    );
}
