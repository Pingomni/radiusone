import Icon from '@/components/Icon';
import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/table';
import { Empty, Panel, Time, State } from '@/components/ui';
import { DeviceTabs } from '@/components/monitoring';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Olt = { id: number; name: string; model: string; host: string; enabled: boolean; onus: number; online: number; lastSync: string | null; ok: boolean | null; error: string };

function OltState({ olt }: { olt: Olt }) {
    if (!olt.enabled) return <State>Off</State>;
    if (olt.ok === null) return <State tone="info">Waiting for the first read</State>;
    return olt.ok ? <State tone="success">OK</State> : <span title={olt.error}><State tone="danger">Problem</State></span>;
}

export default function OltIndex({ olts }: { olts: Olt[] }) {
    const can = useCan();
    return (
        <AppLayout
            title="Devices"
            actions={(
                <>
                    <Link className="button" href="/olts/changelog">Changelog</Link>
                    <Link className="button" href="/olts/mapping">Client mapping</Link>
                    {can('nas.manage') && <Link className="button primary" href="/olts/create"><Icon name="plus" />New OLT</Link>}
                </>
            )}
        >
            <DeviceTabs current="olts" />
            <Panel title="OLTs">
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
                            { header: 'State', cell: (o) => <OltState olt={o} /> },
                        ]}
                    />
                ) : <Empty>No OLT yet. Add one with New OLT.</Empty>}
            </Panel>
        </AppLayout>
    );
}
