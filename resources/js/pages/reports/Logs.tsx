import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable } from '@/components/table';
import { Badge, Empty, Panel, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan, useShared } from '@/lib/auth';
import { query } from '@/lib/format';

type Filters = { q: string; reply: string; auth_user: string };
type Audit = { id: number; time: string; actor: string; action: string; entityType: string; entityId: string; ip: string };
type Attempt = { time: string; username: string; reply: string };

function SearchForm({ filters, field, label, placeholder }: { filters: Filters; field: 'q' | 'auth_user'; label: string; placeholder: string }) {
    const [value, setValue] = useState(filters[field]);
    return (
        <form
            className="toolbar list-filterbar"
            action="/logs"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(`/logs${query({ ...filters, [field]: value })}`, {}, { preserveScroll: true });
            }}
        >
            <label className="list-search"><span>{label}</span><input type="search" name={field} value={value} placeholder={placeholder} onChange={(e) => setValue(e.target.value)} /></label>
            <div className="list-filter-buttons">
                <button>Filter</button>
                {field === 'q' && filters.q !== '' && <Link className="button" href={`/logs${query({ ...filters, q: '' })}`}>Clear</Link>}
            </div>
        </form>
    );
}

export default function Logs({ filters, metrics, audit, auditMatches, attempts }: { filters: Filters; metrics: Metric[]; audit: Audit[]; auditMatches: number; attempts: Attempt[] | null }) {
    const can = useCan();
    const { clock } = useShared();
    const tz = clock.timezone;
    const matches = auditMatches > audit.length
        ? `Showing the latest ${audit.length} of ${auditMatches} matching events.`
        : `${auditMatches} matching ${auditMatches === 1 ? 'event' : 'events'}.`;
    const replies: [string, string][] = [['', 'All'], ['reject', 'Rejects'], ['accept', 'Accepts']];

    return (
        <AppLayout title="Logs">
            <MetricCards metrics={metrics} />
            <div className="stack-panels">
                <Panel title="Admin audit log">
                    <SearchForm key={`q-${filters.q}`} filters={filters} field="q" label="Search audit log" placeholder="Operator, action, entity or IP" />
                    <p className="hint">{matches}</p>
                    <DataTable
                        rows={audit}
                        rowKey={(r) => r.id}
                        exportRows
                        resetHref={filters.q ? '/logs' : undefined}
                        columns={[
                            { header: 'Time', cell: (r) => <Time value={r.time} timezone={tz} /> },
                            { header: 'Actor', cell: (r) => r.actor },
                            { header: 'Action', cell: (r) => r.action },
                            {
                                header: 'Entity',
                                cell: (r) => (r.entityType === 'client' && r.entityId !== 'multiple' && can('clients.view')
                                    ? <Link href={`/clients/${encodeURIComponent(r.entityId)}/edit`}>client:{r.entityId}</Link>
                                    : `${r.entityType}:${r.entityId}`),
                            },
                            { header: 'IP', cell: (r) => <span className="mono">{r.ip}</span> },
                        ]}
                    />
                </Panel>
                <Panel title="Authentication attempts (latest 200)">
                    {attempts === null ? (
                        <Empty>radpostauth table not found. Enable FreeRADIUS post-auth SQL logging to populate this panel.</Empty>
                    ) : (
                        <>
                            <nav className="segmented" aria-label="Filter by reply">
                                {replies.map(([value, label]) => (
                                    <Link key={value} href={`/logs${query({ ...filters, reply: value })}`} aria-current={filters.reply === value ? 'page' : undefined} preserveScroll>{label}</Link>
                                ))}
                            </nav>
                            <SearchForm key={`u-${filters.auth_user}`} filters={filters} field="auth_user" label="Username" placeholder="Exact or partial username" />
                            <DataTable
                                rows={attempts}
                                exportRows
                                resetHref={filters.reply || filters.auth_user ? `/logs${query({ ...filters, reply: '', auth_user: '' })}` : undefined}
                                columns={[
                                    { header: 'Time', cell: (r) => <Time value={r.time} timezone={tz} /> },
                                    { header: 'Username', cell: (r) => (can('clients.view') ? <Link href={`/clients?q=${encodeURIComponent(r.username)}`}>{r.username}</Link> : r.username) },
                                    { header: 'Reply', cell: (r) => (/reject/i.test(r.reply) ? <Badge tone="danger">{r.reply}</Badge> : r.reply) },
                                ]}
                            />
                        </>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
