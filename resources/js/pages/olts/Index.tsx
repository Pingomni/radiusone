import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/table';
import { Badge, Empty, Panel, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Olt = { id: number; name: string; model: string; host: string; enabled: boolean; onus: number; online: number; lastSync: string | null; ok: boolean | null; error: string };

function State({ olt }: { olt: Olt }) {
    if (!olt.enabled) return <Badge>Off</Badge>;
    if (olt.ok === null) return <Badge tone="info">Waiting for the first read</Badge>;
    return olt.ok ? <Badge tone="success">OK</Badge> : <span title={olt.error}><Badge tone="danger">Problem</Badge></span>;
}

export default function OltIndex({ olts }: { olts: Olt[] }) {
    const can = useCan();
    return (
        <AppLayout title="OLT">
            <div className="toolbar">
                <Link className="button" href="/olts/mapping">Client mapping</Link>
                {can('nas.manage') && <Link className="button primary" href="/olts/create">New OLT</Link>}
            </div>
            <Panel title="OLTs">
                <p className="hint">The portal only reads from the OLT. It never changes anything on it.</p>
                {olts.length ? (
                    <DataTable
                        rows={olts}
                        rowKey={(o) => o.id}
                        columns={[
                            { header: 'Name', cell: (o) => <Link href={`/olts/${o.id}`}>{o.name}</Link> },
                            { header: 'Model', cell: (o) => o.model },
                            { header: 'Address', cell: (o) => <span className="mono">{o.host}</span> },
                            { header: 'ONUs online', cell: (o) => `${o.online} / ${o.onus}` },
                            { header: 'Last read', cell: (o) => <Time value={o.lastSync} /> },
                            { header: 'State', cell: (o) => <State olt={o} /> },
                        ]}
                    />
                ) : <Empty>No OLT yet. Add one with New OLT.</Empty>}
            </Panel>
        </AppLayout>
    );
}
