import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { DataTable, Pagination } from '@/components/table';
import { Status, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';
import type { Paginated } from '@/lib/types';

type Row = {
    id: number; username: string; name: string; known: boolean; service: string; online: boolean; nasId: number | null; router: string;
    ip: string; mac: string; started: string; updated: string; time: string; down: string; up: string; canDisconnect: boolean;
};
type Filters = { q: string; view: string; nas: number };
type Counts = { online: number; stale: number; all: number };

const VIEWS: [view: string, label: string, key: keyof Counts][] = [['', 'Online', 'online'], ['stale', 'No recent update', 'stale'], ['all', 'All open', 'all']];

/** Empty filters stay out of the address. */
const clean = (f: Filters) => ({ ...(f.view ? { view: f.view } : {}), ...(f.nas ? { nas: f.nas } : {}), ...(f.q ? { q: f.q } : {}) });

/** Every open session on every router. */
export default function Sessions({ routers, counts, filters, result }: { routers: { id: number; name: string }[]; counts: Counts; filters: Filters; result: Paginated<Row> }) {
    const { clock } = useShared();
    const [q, setQ] = useState(filters.q);
    const go = (next: Filters) => router.get('/sessions', clean(next), { preserveState: true, preserveScroll: true });
    return (
        <AppLayout title="Sessions" subtitle={`${counts.online.toLocaleString('en-US')} online on ${routers.length} router${routers.length === 1 ? '' : 's'}.`}>
            <section className="panel clients-page" aria-label="Sessions">
                <nav className="view-tabs" aria-label="Session views">
                    {VIEWS.map(([view, label, key]) => (
                        <Link key={key} href="/sessions" data={clean({ ...filters, view })} aria-current={filters.view === view ? 'page' : undefined} preserveState preserveScroll>
                            {label} <span className="view-count">{counts[key].toLocaleString('en-US')}</span>
                        </Link>
                    ))}
                </nav>
                <form className="clients-toolbar" role="search" onSubmit={(e) => { e.preventDefault(); go({ ...filters, q }); }}>
                    <input type="search" name="q" aria-label="Search sessions" placeholder="Username, name, IP or MAC" value={q} onChange={(e) => setQ(e.target.value)} />
                    {routers.length > 1 && (
                        <select aria-label="Router" value={filters.nas} onChange={(e) => go({ ...filters, q, nas: Number(e.target.value) })}>
                            <option value={0}>Every router</option>
                            {routers.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                    )}
                    <button className="button"><Icon name="search" />Search</button>
                </form>
                <DataTable
                    rows={result.rows}
                    rowKey={(r) => r.id}
                    serverPaginated
                    emptyText={filters.q || filters.nas ? 'No session matches.' : filters.view === '' ? 'Nobody is online.' : 'No open sessions.'}
                    columns={[
                        { header: 'Client', cell: (r) => <span className="list-cell-stack">{r.known ? <Link href={`/clients/${encodeURIComponent(r.username)}/edit`}>{r.name || r.username}</Link> : <strong>{r.username}</strong>}<span className="list-subline mono">{r.username}</span></span> },
                        { header: 'Status', cell: (r) => <Status tone={r.online ? 'good' : 'warning'}>{r.online ? 'Online' : 'No recent update'}</Status> },
                        { header: 'Router', cell: (r) => (r.nasId ? <Link href={`/nas/${r.nasId}/edit`}>{r.router}</Link> : r.router) },
                        { header: 'IP and MAC', cell: (r) => <span className="list-cell-stack"><span className="mono">{r.ip || '-'}</span><span className="list-subline mono">{r.mac}</span></span> },
                        { header: 'Service', cell: (r) => r.service || '-' },
                        { header: 'Started', cell: (r) => <span className="list-cell-stack"><Time value={r.started} timezone={clock.timezone} /><span className="list-subline">{r.time}</span></span> },
                        { header: 'Down / up', className: 'num', cell: (r) => `${r.down} / ${r.up}` },
                        {
                            header: '',
                            cell: (r) => (r.canDisconnect && r.nasId ? (
                                <PostButton
                                    action={`/clients/${encodeURIComponent(r.username)}/disconnect`}
                                    data={{ nas_id: r.nasId, radacctid: r.id, back: 'sessions' }}
                                    label="Disconnect"
                                    confirm={{ title: `Disconnect ${r.username}?`, message: `Ends the session on ${r.router} (${r.ip}). Most CPEs redial on their own.`, confirmLabel: 'Disconnect' }}
                                />
                            ) : null),
                        },
                    ]}
                />
                <Pagination result={result} path="/sessions" query={clean(filters)} noun="sessions" />
            </section>
        </AppLayout>
    );
}
