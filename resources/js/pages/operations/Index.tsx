import { Link } from '@inertiajs/react';
import { PostButton } from '@/components/confirm';
import { MetricCards, type Metric } from '@/components/metrics';
import { DataTable } from '@/components/table';
import { Panel, Tag, Time, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';

type Operation = { id: number; username: string; action: string; revision: string; state: TagData; result: string; created: string; retry: boolean };

export default function Operations({ worker, metrics, operations }: { worker: { stuck: boolean; detail: string }; metrics: Metric[]; operations: Operation[] }) {
    const { clock } = useShared();
    return (
        <AppLayout title="Network operations">
            {worker.stuck && (
                <div className="flash danger" role="status">
                    <strong>The network worker is not running.</strong> {worker.detail} Queued changes stay here until the network operations worker is scheduled.
                </div>
            )}
            <p className="hint">The latest 100 router changes queued by the portal (for example speed changes or disconnects).</p>
            <MetricCards metrics={metrics} />
            <Panel title="Operations">
                <DataTable
                    rows={operations}
                    rowKey={(o) => o.id}
                    exportRows
                    columns={[
                        { header: 'ID', cell: (o) => o.id },
                        { header: 'Customer', cell: (o) => <Link href={`/clients/${encodeURIComponent(o.username)}/edit`}>{o.username}</Link> },
                        { header: 'Action', cell: (o) => o.action },
                        { header: 'Revision', cell: (o) => o.revision },
                        { header: 'State', cell: (o) => <Tag tag={o.state} /> },
                        { header: 'Result', cell: (o) => o.result },
                        { header: 'Created', cell: (o) => <Time value={o.created} timezone={clock.timezone} /> },
                        {
                            header: 'Review',
                            noExport: true,
                            cell: (o) => (
                                <>
                                    <Link href={`/clients/${encodeURIComponent(o.username)}/diagnostics`}>Diagnose</Link>
                                    {o.retry && (
                                        <>
                                            {' '}
                                            <PostButton
                                                action={`/operations/${o.id}/retry`}
                                                className="link"
                                                label="Reconcile / retry"
                                                confirm={{ title: `Reconcile operation #${o.id}?`, message: 'The operation is marked uncertain and the worker observes the original session again before resending.', confirmLabel: 'Reconcile' }}
                                            />
                                        </>
                                    )}
                                </>
                            ),
                        },
                    ]}
                />
            </Panel>
        </AppLayout>
    );
}
