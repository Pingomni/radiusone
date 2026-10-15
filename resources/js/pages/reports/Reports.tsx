import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable } from '@/components/table';
import { ConnectionState, Panel, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan, useShared } from '@/lib/auth';
import { query } from '@/lib/format';

type Filters = { from: string; to: string; username: string; max: string };
type Online = { id: number; username: string; ip: string; nas: string; started: string; updated: string; state: string };
type Daily = { day: string; down: string; up: string; total: string };
type Session = { id: number; username: string; start: string; stop: string; time: string; down: string; up: string; quality: string };

function RangeFilter({ filters }: { filters: Filters }) {
    const [values, setValues] = useState(filters);
    return (
        <form
            className="toolbar list-filterbar report-range"
            action="/reports"
            onSubmit={(event) => {
                event.preventDefault();
                router.get('/reports', { from: values.from, to: values.to, ...(values.username ? { username: values.username } : {}) }, { preserveScroll: true });
            }}
        >
            <label><span>From</span><input type="date" name="from" value={values.from} max={filters.max} onChange={(e) => setValues({ ...values, from: e.target.value })} /></label>
            <label><span>To</span><input type="date" name="to" value={values.to} max={filters.max} onChange={(e) => setValues({ ...values, to: e.target.value })} /></label>
            <label className="list-search"><span>Username (exact)</span><input type="search" name="username" value={values.username} placeholder="All users" onChange={(e) => setValues({ ...values, username: e.target.value })} /></label>
            <div className="list-filter-buttons"><button>Apply</button><Link className="button" href="/reports">Last 31 days</Link></div>
        </form>
    );
}

export default function Reports({ filters, metrics, quality, online, daily, sessions, sessionMatches }: {
    filters: Filters;
    metrics: Metric[];
    quality: { stale: number; anomalous: number };
    online: Online[];
    daily: Daily[];
    sessions: Session[];
    sessionMatches: number;
}) {
    const can = useCan();
    const { clock } = useShared();
    const tz = clock.timezone;
    const user = (name: string) => (can('clients.view') ? <Link href={`/reports${query({ from: filters.from, to: filters.to, username: name })}`}>{name}</Link> : name);
    const rangeLabel = `${filters.from} to ${filters.to}${filters.username ? `, ${filters.username}` : ''}`;
    const count = sessionMatches > sessions.length ? `latest ${sessions.length} of ${sessionMatches}` : String(sessionMatches);

    return (
        <AppLayout title="Reports">
            <MetricCards metrics={metrics} />
            <div className="notice">
                Counters are cumulative session snapshots, not usage within a day or billing period. Dates use the database timezone. Fresh means an accounting update within five minutes, not verified connectivity. Open stale/unknown sessions: {quality.stale}; timestamp/counter anomalies: {quality.anomalous}. Raw records are retained.
            </div>
            <div className="stack-panels">
                <Panel title="Open accounting sessions (latest 200)">
                    <DataTable
                        rows={online}
                        rowKey={(r) => r.id}
                        exportRows
                        columns={[
                            { header: 'Username', cell: (r) => user(r.username) },
                            { header: 'IP', cell: (r) => <span className="mono">{r.ip}</span> },
                            { header: 'NAS', cell: (r) => r.nas },
                            { header: 'Started', cell: (r) => <Time value={r.started} timezone={tz} /> },
                            { header: 'Last update', cell: (r) => <Time value={r.updated} timezone={tz} /> },
                            { header: 'State', cell: (r) => <ConnectionState state={r.state} /> },
                        ]}
                    />
                </Panel>
                <Panel title={`Session totals by start date (${rangeLabel})`}>
                    <RangeFilter key={rangeLabel} filters={filters} />
                    <DataTable
                        rows={daily}
                        rowKey={(r) => r.day}
                        exportRows
                        resetHref={filters.username ? '/reports' : undefined}
                        columns={[
                            { header: 'Start date', cell: (r) => r.day },
                            { header: 'Download total', cell: (r) => r.down },
                            { header: 'Upload total', cell: (r) => r.up },
                            { header: 'Total', cell: (r) => r.total },
                        ]}
                    />
                </Panel>
            </div>
            <Panel title={`Accounting sessions started ${rangeLabel} (${count})`}>
                <DataTable
                    rows={sessions}
                    rowKey={(r) => r.id}
                    exportRows
                    columns={[
                        { header: 'Username', cell: (r) => user(r.username) },
                        { header: 'Start', cell: (r) => <Time value={r.start} timezone={tz} /> },
                        { header: 'Stop', cell: (r) => <Time value={r.stop} timezone={tz} /> },
                        { header: 'Time', cell: (r) => r.time },
                        { header: 'Download total', cell: (r) => r.down },
                        { header: 'Upload total', cell: (r) => r.up },
                        { header: 'Quality', cell: (r) => r.quality },
                    ]}
                />
            </Panel>
        </AppLayout>
    );
}
