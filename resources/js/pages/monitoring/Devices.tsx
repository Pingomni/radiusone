import { Link } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { DeviceTabs } from '@/components/monitoring';
import { DataTable } from '@/components/table';
import { Status, type StatusTone } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Device = {
    key: string; kind: 'router' | 'olt'; name: string; address: string; model: string; href: string; monitor: string;
    tone: StatusTone; state: string; count: string; countHref: string; detail: string; problems: string[];
};
type Counts = { all: number; router: number; olt: number; problems: number };

/** Routers and OLTs in one list, with their state. */
export default function Devices({ counts, view, devices, canAdd }: { counts: Counts; view: string; devices: Device[]; canAdd: boolean }) {
    return (
        <AppLayout
            title="Devices"
            subtitle={`${counts.router} router${counts.router === 1 ? '' : 's'} and ${counts.olt} OLT${counts.olt === 1 ? '' : 's'}.`}
            actions={canAdd ? (
                <>
                    <Link className="button" href="/nas/create"><Icon name="plus" />New router</Link>
                    <Link className="button" href="/olts/create"><Icon name="plus" />New OLT</Link>
                </>
            ) : undefined}
        >
            <DeviceTabs current={view === 'problems' ? 'problems' : 'all'} counts={counts} />
            <section className="panel clients-page" aria-label="Devices">
                <DataTable
                    rows={devices}
                    rowKey={(d) => d.key}
                    perPage={50}
                    emptyText={view === 'problems' ? 'Every device is working.' : 'No devices yet.'}
                    columns={[
                        { header: 'Device', cell: (d) => <span className="list-cell-stack"><Link href={d.href}>{d.name}</Link><span className="list-subline">{d.kind === 'olt' ? 'OLT' : 'Router'} · {d.model}</span></span> },
                        { header: 'Address', cell: (d) => <span className="mono">{d.address}</span> },
                        { header: 'State', cell: (d) => <Status tone={d.tone}>{d.state}</Status> },
                        { header: 'In use', cell: (d) => <Link href={d.countHref}>{d.count}</Link> },
                        { header: 'Readings', className: 'wrap', cell: (d) => d.detail || <span className="is-empty-text">-</span> },
                        { header: 'Needs a look', className: 'wrap', cell: (d) => (d.problems.length ? <span className="field-warning">{d.problems.slice(0, 2).join(' ')}{d.problems.length > 2 ? ` and ${d.problems.length - 2} more.` : ''}</span> : <span className="is-empty-text">-</span>) },
                        { header: '', cell: (d) => <Link className="nowrap" href={d.monitor}>{d.kind === 'olt' ? 'Graphs' : 'Monitor'}</Link> },
                    ]}
                />
            </section>
        </AppLayout>
    );
}
