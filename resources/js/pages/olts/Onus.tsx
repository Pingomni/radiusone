import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { OnuStatus, SignalReading, type OnuRow } from '@/components/olt';
import { DataTable, Pagination } from '@/components/table';
import { State, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import type { Paginated } from '@/lib/types';

type Row = OnuRow & { oltId: number; olt: string; weak: number; veryWeak: number };
type Waiting = { sn: string; position: string; model: string; oltId: number; olt: string; canAdd: boolean };
type Filters = { q: string; status: string; olt: number; board: string; port: string; vlan: number; type: string; profile: string; linked: string };
type Choices = { boards: string[]; ports: string[]; vlans: number[]; types: string[]; profiles: string[] };
type Counts = { total: number; online: number; offline: number; weak: number; waiting: number };

const VIEWS: [status: string, label: string, key: keyof Counts][] = [
    ['', 'All', 'total'], ['online', 'Online', 'online'], ['offline', 'Offline', 'offline'], ['weak', 'Weak signal', 'weak'], ['waiting', 'Waiting', 'waiting'],
];

/** Empty filters stay out of the address. */
const clean = (f: Filters) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== 0)) as Record<string, string | number>;

/** Every ONU on every OLT: find one without first choosing its OLT. */
export default function Onus({ olts, counts, filters, choices, waiting, result }: { olts: { id: number; name: string }[]; counts: Counts; filters: Filters; choices: Choices; waiting: Waiting[]; result: Paginated<Row> }) {
    const [q, setQ] = useState(filters.q);
    const go = (next: Filters) => router.get('/onus', clean(next), { preserveState: true, preserveScroll: true });
    const several = olts.length > 1;
    const filtered = !!(filters.olt || filters.board || filters.port || filters.vlan || filters.type || filters.profile || filters.linked || filters.q);
    // One filter changed: the list follows at once. A new OLT or card drops the card and port chosen under the old one.
    const set = (change: Partial<Filters>) => go({ ...filters, q, ...change });
    const pick = (label: string, value: string | number, options: [string | number, string][], onChange: (v: string) => void, any = 'Any') => (
        <label className="onu-filter">
            <span>{label}</span>
            <select value={value} onChange={(e) => onChange(e.target.value)}>
                <option value={typeof value === 'number' ? 0 : ''}>{any}</option>
                {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
            </select>
        </label>
    );
    return (
        <AppLayout title="ONUs" subtitle={`${counts.total.toLocaleString('en-US')} on ${olts.length} OLT${olts.length === 1 ? '' : 's'}, ${counts.online.toLocaleString('en-US')} online.`}>
            <section className="panel clients-page" aria-label="ONUs">
                <nav className="view-tabs" aria-label="ONU views">
                    {VIEWS.map(([status, label, key]) => (
                        <Link key={key} href="/onus" data={clean({ ...filters, status })} aria-current={filters.status === status ? 'page' : undefined} preserveState preserveScroll>
                            {label} <span className="view-count">{counts[key].toLocaleString('en-US')}</span>
                        </Link>
                    ))}
                </nav>
                <form className="clients-toolbar" role="search" onSubmit={(e) => { e.preventDefault(); go({ ...filters, q }); }}>
                    <input type="search" name="q" aria-label="Search ONUs" placeholder="Serial, MAC, IP, name, address or phone" value={q} onChange={(e) => setQ(e.target.value)} />
                    <button className="button"><Icon name="search" />Search</button>
                    {filtered && <Link className="filter-chip" href="/onus" data={filters.status ? { status: filters.status } : {}} onClick={() => setQ('')}>Clear filters <Icon name="close" /></Link>}
                </form>
                <div className="onu-filters">
                    {several && pick('OLT', filters.olt, olts.map((o) => [o.id, o.name]), (v) => set({ olt: Number(v), board: '', port: '' }))}
                    {pick('Board', filters.board, choices.boards.map((b) => [b, b]), (v) => set({ board: v, port: '' }))}
                    {pick('Port', filters.port, choices.ports.map((p) => [p, p]), (v) => set({ port: v }), filters.board ? 'Any' : 'Choose a board')}
                    {pick('VLAN', filters.vlan, choices.vlans.map((v) => [v, String(v)]), (v) => set({ vlan: Number(v) }))}
                    {pick('ONU type', filters.type, choices.types.map((t) => [t, t]), (v) => set({ type: v }))}
                    {pick('Profile', filters.profile, choices.profiles.map((t) => [t, t]), (v) => set({ profile: v }))}
                    {pick('Client', filters.linked, [['yes', 'Linked'], ['no', 'Not linked']], (v) => set({ linked: v }))}
                </div>
                {filters.status === 'waiting' ? (
                    <DataTable
                        rows={waiting}
                        rowKey={(w) => `${w.oltId}-${w.sn}`}
                        emptyText="No new ONU is waiting to be added."
                        columns={[
                            { header: 'Serial', cell: (w) => <strong className="mono">{w.sn}</strong> },
                            { header: 'OLT', cell: (w) => <Link href={`/olts/${w.oltId}`}>{w.olt}</Link> },
                            { header: 'Port', cell: (w) => <span className="mono">{w.position}</span> },
                            { header: 'Model', cell: (w) => w.model || '-' },
                            { header: '', cell: (w) => (w.canAdd ? <Link className="button" href={`/olts/${w.oltId}/provision`}><Icon name="plus" />Add</Link> : null) },
                        ]}
                    />
                ) : (
                    <>
                        <DataTable
                            rows={result.rows}
                            rowKey={(o) => o.id}
                            serverPaginated
                            emptyText={counts.total ? 'No ONU matches.' : 'No ONUs read yet.'}
                            columns={[
                                { header: 'ONU', className: 'wrap', cell: (o) => <Link className="olt-onu-cell" href={`/olts/${o.oltId}/onus/${o.id}`}><strong>{o.description || o.sn}</strong><small className="mono">{o.sn}</small></Link> },
                                { header: 'Status', cell: (o) => (o.active === false ? <State tone="warning">Turned off</State> : <OnuStatus status={o.status} />) },
                                { header: 'OLT and port', cell: (o) => <span className="list-cell-stack"><Link href={`/olts/${o.oltId}`}>{o.olt}</Link><span className="list-subline mono">{o.position}</span></span> },
                                { header: 'Client', cell: (o) => (o.clients.length ? o.clients.map((u) => <div key={u}><Link href={`/clients/${encodeURIComponent(u)}/edit?tab=onu`}>{u}</Link></div>) : <span className="onu-pending">Not linked</span>) },
                                { header: 'Signal at the ONU, dBm', cell: (o) => <SignalReading dbm={o.rx} weak={o.weak} veryWeak={o.veryWeak} oltDbm={o.oltRx} /> },
                                { header: 'Distance', cell: (o) => (o.distance === null ? '-' : `${o.distance} m`) },
                                { header: 'Last offline', className: 'wrap', cell: (o) => (o.lastDown || o.downCause ? <>{o.lastDown && <Time value={o.lastDown} />}{o.downCause && <div>{o.downCause}</div>}</> : '-') },
                                { header: 'Model', cell: (o) => o.model || '-' },
                            ]}
                        />
                        <Pagination result={result} path="/onus" query={clean(filters)} noun="ONUs" />
                    </>
                )}
            </section>
        </AppLayout>
    );
}
