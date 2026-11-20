import { Link, router } from '@inertiajs/react';
import { OnuPower } from '@/components/olt';
import { DataTable } from '@/components/table';
import { State, Status } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Port = {
    key: string; oltId: number; olt: string; port: string; alias: string; admin: boolean | null; oper: boolean | null; online: number; total: number; free: number;
    signal: number | null; weak: number; veryWeak: number; tx: number | null; temperature: number | null; autofind: boolean | null; problems: string[]; danger: boolean;
};
type Filters = { view: string; olt: number };
type Counts = { all: number; used: number; problems: number };

const VIEWS: [view: string, label: string, key: keyof Counts][] = [['', 'All', 'all'], ['used', 'In use', 'used'], ['problems', 'Needs a look', 'problems']];
const clean = (f: Filters) => ({ ...(f.view ? { view: f.view } : {}), ...(f.olt ? { olt: f.olt } : {}) });

/** The PON ports of every OLT. */
export default function Ports({ olts, counts, filters, ports }: { olts: { id: number; name: string }[]; counts: Counts; filters: Filters; ports: Port[] }) {
    const onus = (p: Port, status = '') => `/onus?olt=${p.oltId}&port=${encodeURIComponent(p.port)}${status ? `&status=${status}` : ''}`;
    return (
        <AppLayout title="PON ports" subtitle={`${counts.all} on ${olts.length} OLT${olts.length === 1 ? '' : 's'}, ${counts.used} in use.`}>
            <section className="panel clients-page" aria-label="PON ports">
                <nav className="view-tabs" aria-label="Port views">
                    {VIEWS.map(([view, label, key]) => (
                        <Link key={key} href="/ports" data={clean({ ...filters, view })} aria-current={filters.view === view ? 'page' : undefined} preserveState preserveScroll>
                            {label} <span className="view-count">{counts[key]}</span>
                        </Link>
                    ))}
                </nav>
                {olts.length > 1 && (
                    <div className="clients-toolbar">
                        <select aria-label="OLT" value={filters.olt} onChange={(e) => router.get('/ports', clean({ ...filters, olt: Number(e.target.value) }), { preserveState: true, preserveScroll: true })}>
                            <option value={0}>Every OLT</option>
                            {olts.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </select>
                    </div>
                )}
                <DataTable
                    rows={ports}
                    rowKey={(p) => p.key}
                    perPage={50}
                    emptyText={filters.view === 'problems' ? 'Every port is fine.' : 'No PON ports read yet.'}
                    columns={[
                        { header: 'Port', cell: (p) => <span className="list-cell-stack"><Link className="mono" href={onus(p)}>{p.port}</Link>{p.alias && <span className="list-subline">{p.alias}</span>}</span> },
                        { header: 'OLT', cell: (p) => <Link href={`/olts/${p.oltId}?tab=ports`}>{p.olt}</Link> },
                        { header: 'State', cell: (p) => (p.admin === false ? <Status tone="neutral">Turned off</Status> : p.oper === true ? <Status tone="good">Up</Status> : p.oper === false ? <Status tone={p.total ? 'warning' : 'neutral'}>Down</Status> : <Status tone="neutral">Unknown</Status>) },
                        { header: 'ONUs online', className: 'num', cell: (p) => (p.total ? <Link href={onus(p)}><strong>{p.online}</strong> / {p.total}</Link> : <span className="is-empty-text">0</span>) },
                        { header: 'Offline', className: 'num', cell: (p) => (p.total - p.online > 0 ? <Link className="field-warning" href={onus(p, 'offline')}>{p.total - p.online}</Link> : <span className="is-empty-text">0</span>) },
                        { header: 'Free', className: 'num', cell: (p) => p.free },
                        { header: 'Average signal', cell: (p) => <OnuPower dbm={p.signal} weak={p.weak} veryWeak={p.veryWeak} /> },
                        { header: 'Transmit', cell: (p) => (p.tx === null ? '-' : `${p.tx.toFixed(2)} dBm`) },
                        { header: 'Module', cell: (p) => (p.temperature === null ? '-' : `${p.temperature} °C`) },
                        { header: 'Needs a look', className: 'wrap', cell: (p) => (p.problems.length ? <State tone={p.danger ? 'danger' : 'warning'}>{p.problems.join(', ')}</State> : <span className="is-empty-text">-</span>) },
                    ]}
                />
            </section>
        </AppLayout>
    );
}
