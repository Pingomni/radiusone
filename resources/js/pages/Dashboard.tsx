import { Link } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { LivePanel } from '@/components/LivePanel';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable } from '@/components/table';
import { Empty, Panel, Status, Time, type StatusTone } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan, useShared } from '@/lib/auth';

type Router = { id: number; name: string; vendor: string; address: string; href: string | null; tone: StatusTone; status: string; title: string; sessions: number; cpu: number | null; uptime: string | null };
type Attention = { tone: 'danger' | 'warning'; title: string; detail: string; action: { label: string; href: string } | null };
type ExpiringRow = { username: string; name: string; service: string; expires: string; when: string; soon: boolean; balance: number | null; balanceText: string | null };
type Reject = { username: string; reply: string; authdate: string };
type TopUser = { username: string; down: string; up: string; total: string };

function Routers({ rows }: { rows: Router[] }) {
    return (
        <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            emptyText="No NAS devices yet."
            columns={[
                { header: 'Router', cell: (r) => <span className="list-cell-stack">{r.href ? <Link href={r.href}>{r.name}</Link> : <strong>{r.name}</strong>}{r.vendor && <span className="list-subline">{r.vendor}</span>}</span> },
                { header: 'Address', cell: (r) => <span className="mono">{r.address}</span> },
                { header: 'Status', cell: (r) => <span title={r.title}><Status tone={r.tone}>{r.status}</Status></span> },
                { header: 'CPU', cell: (r) => (r.cpu === null ? <span className="is-empty-text">-</span> : <span className="cpu-cell"><span className="cpu-bar"><i className={r.cpu > 70 ? 'is-high' : undefined} style={{ width: `${r.cpu}%` }} /></span>{r.cpu}%</span>) },
                { header: 'Sessions', className: 'num', cell: (r) => <strong>{r.sessions.toLocaleString('en-US')}</strong> },
                { header: 'Uptime', cell: (r) => <span className="list-subline">{r.uptime ?? '-'}</span> },
            ]}
        />
    );
}

function NeedsAttention({ items }: { items: Attention[] }) {
    if (!items.length) return <Empty>Nothing needs a look.</Empty>;
    return (
        <ul className="attention-list">
            {items.map((item) => (
                <li key={item.title}>
                    <span className={`status-dot is-${item.tone}`} aria-hidden="true" />
                    <div>
                        <strong><span className="sr-only">{item.tone === 'danger' ? 'Urgent: ' : 'Needs a look: '}</span>{item.title}</strong>
                        {item.detail && <span>{item.detail}</span>}
                    </div>
                    {item.action && <Link className="button" href={item.action.href}>{item.action.label}</Link>}
                </li>
            ))}
        </ul>
    );
}

export default function Dashboard({ state, kpis, routers, routerTotal, attention, expiring, rejects, topUsers }: {
    state: { headline: string; line: string };
    kpis: Metric[];
    routers: Router[];
    routerTotal: number;
    attention: Attention[];
    expiring: { total: number; rows: ExpiringRow[] } | null;
    rejects: Reject[] | null;
    topUsers: TopUser[];
}) {
    const can = useCan();
    const { clock } = useShared();
    const clientLink = (username: string, text: string) => (can('clients.view') ? <Link href={`/clients/${encodeURIComponent(username)}/edit`}>{text}</Link> : text);
    const showBalance = !!expiring?.rows.some((r) => r.balance !== null);
    return (
        <AppLayout title="Overview" heading={false}>
            <div className="page-head with-actions">
                <div>
                    <h1>{state.headline}</h1>
                    <p>{state.line}</p>
                </div>
                {can('clients.manage') && <Link className="button primary" href="/clients/create"><Icon name="plus" />New client</Link>}
            </div>
            <LivePanel title="Key figures" url="/fragments/dashboard-kpis" interval={60} initial={kpis} className="live-bare overview-figures" render={(m) => <MetricCards metrics={m} />} />
            <div className="overview-grid">
                <LivePanel
                    title="Routers"
                    url="/fragments/dashboard-nas"
                    interval={30}
                    initial={routers}
                    headerLink={can('nas.view') && routerTotal > routers.length ? <Link href="/nas">All {routerTotal}</Link> : undefined}
                    render={(rows) => <Routers rows={rows} />}
                />
                <Panel title="Needs attention">
                    <NeedsAttention items={attention} />
                </Panel>
            </div>
            <div className="overview-grid thirds">
                {expiring && (
                    <Panel title="Expiring this week" actions={expiring.total > expiring.rows.length ? <span className="list-subline">{expiring.total} in all</span> : undefined}>
                        <DataTable
                            rows={expiring.rows}
                            rowKey={(r) => r.username}
                            emptyText="No client expires in the next 7 days."
                            columns={[
                                { header: 'Client', cell: (r) => <span className="list-cell-stack">{clientLink(r.username, r.name || r.username)}<span className="list-subline">{r.service}</span></span> },
                                { header: 'Expires', cell: (r) => <span className={r.soon ? 'field-warning' : undefined} title={r.expires}>{r.when}</span> },
                                ...(showBalance ? [{ header: 'Balance', className: 'num', cell: (r: ExpiringRow) => <span className={r.balance !== null && r.balance < 0 ? 'danger-text' : 'is-empty-text'}>{r.balanceText}</span> }] : []),
                            ]}
                        />
                    </Panel>
                )}
                <Panel title="Top users this month">
                    <DataTable
                        rows={topUsers}
                        rowKey={(r) => r.username}
                        emptyText="No sessions this month yet."
                        columns={[
                            { header: 'Client', cell: (r) => clientLink(r.username, r.username) },
                            { header: 'Down', className: 'num', cell: (r) => r.down },
                            { header: 'Up', className: 'num', cell: (r) => <span className="list-subline">{r.up}</span> },
                            { header: 'Total', className: 'num', cell: (r) => <strong>{r.total}</strong> },
                        ]}
                    />
                </Panel>
                {rejects !== null && (
                    <LivePanel
                        title="Failed logins"
                        url="/fragments/dashboard-rejects"
                        interval={30}
                        initial={rejects}
                        headerLink={<Link href="/logs?reply=reject">All</Link>}
                        render={(rows) => (
                            <DataTable
                                rows={rows}
                                emptyText="No failed logins."
                                columns={[
                                    { header: 'Time', cell: (r) => <Time value={r.authdate} timezone={clock.timezone} /> },
                                    { header: 'Username', cell: (r) => (can('clients.view') ? <Link href={`/clients?q=${encodeURIComponent(r.username)}`}>{r.username}</Link> : r.username) },
                                ]}
                            />
                        )}
                    />
                )}
            </div>
        </AppLayout>
    );
}
