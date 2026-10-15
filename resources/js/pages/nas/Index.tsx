import { Link } from '@inertiajs/react';
import { DeleteButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { DataTable } from '@/components/table';
import { Badge, Panel, Tag, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Device = { id: number; name: string; ip: string; vendor: string; enabled: boolean; update: TagData; disconnect: TagData | null; online: number; description: string; pendingDelete: boolean };

export default function NasIndex({ devices }: { devices: Device[] }) {
    const can = useCan();
    return (
        <AppLayout title="NAS Devices">
            <div className="toolbar">
                <Link className="button" href="/monitoring"><Icon name="activity" />Monitoring</Link>
                {can('nas.manage') && <Link className="button primary" href="/nas/create">New NAS device</Link>}
            </div>
            <Panel title="BRAS / NAS Devices">
                <DataTable
                    rows={devices}
                    rowKey={(d) => d.id}
                    exportRows
                    columns={[
                        { header: 'Name', cell: (d) => <Link href={`/nas/${d.id}/edit`}>{d.name}</Link> },
                        { header: 'NAS IP', cell: (d) => <span className="mono">{d.ip}</span> },
                        { header: 'Vendor', cell: (d) => d.vendor },
                        { header: 'RADIUS', cell: (d) => (d.enabled ? 'Accepting' : <Badge tone="warning">Disabled</Badge>) },
                        { header: 'RADIUS update', cell: (d) => <Tag tag={d.update} /> },
                        { header: 'Disconnect', cell: (d) => (d.disconnect ? <Tag tag={d.disconnect} /> : <span className="muted-action">after the update</span>) },
                        { header: 'Online now', cell: (d) => d.online },
                        { header: 'Description', cell: (d) => <span className="cell-truncate" title={d.description}>{d.description}</span> },
                        {
                            header: 'Actions',
                            noExport: true,
                            cell: (d) => (
                                <>
                                    <Link href={`/nas/${d.id}/edit`}>{can('nas.manage') ? 'Edit' : 'View'}</Link>{' '}
                                    <Link href={`/nas/${d.id}/monitor`}>Monitor</Link>{' '}
                                    {can('danger.delete') && !d.pendingDelete && (
                                        <DeleteButton
                                            action={`/nas/${d.id}/delete`}
                                            message={`The NAS is disabled now and removed at the next RADIUS update; after that its requests are rejected. ${d.online} subscriber${d.online === 1 ? ' is' : 's are'} online on it.`}
                                            typeToConfirm={d.name}
                                            title={`Remove NAS ${d.name}?`}
                                        />
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
