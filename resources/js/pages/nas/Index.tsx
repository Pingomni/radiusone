import { Link } from '@inertiajs/react';
import { DeleteButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { DeviceTabs } from '@/components/monitoring';
import { UnknownRouters } from '@/components/NasDiagnostics';
import { DataTable } from '@/components/table';
import { Panel, Tag, type TagData, State } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Device = { id: number; name: string; ip: string; vendor: string; enabled: boolean; update: TagData; disconnect: TagData | null; online: number; snmp: TagData | null; description: string; pendingDelete: boolean };

export default function NasIndex({ devices }: { devices: Device[] }) {
    const can = useCan();
    return (
        <AppLayout
            title="Devices"
            actions={(
                <>
                    <Link className="button" href="/monitoring"><Icon name="activity" />Monitoring</Link>
                    {can('nas.manage') && <Link className="button primary" href="/nas/create"><Icon name="plus" />New router</Link>}
                </>
            )}
        >
            <DeviceTabs current="routers" />
            <UnknownRouters canAdd={can('nas.manage')} />
            <Panel title="Routers">
                <DataTable
                    rows={devices}
                    rowKey={(d) => d.id}
                    exportRows
                    columns={[
                        { header: 'Name', cell: (d) => <><Link href={`/nas/${d.id}/edit`}>{d.name}</Link>{d.description && <span className="list-subline cell-truncate" title={d.description}>{d.description}</span>}</> },
                        { header: 'NAS IP', cell: (d) => <><span className="mono">{d.ip}</span><span className="list-subline">{d.vendor}</span></> },
                        { header: 'RADIUS', cell: (d) => (d.enabled ? 'Accepting' : <State tone="warning">Disabled</State>) },
                        { header: 'RADIUS update', cell: (d) => <Tag tag={d.update} /> },
                        { header: 'Disconnect', cell: (d) => (d.disconnect ? <Tag tag={d.disconnect} /> : <span className="muted-action">after the update</span>) },
                        { header: 'Online now', cell: (d) => d.online },
                        { header: 'SNMP', cell: (d) => (d.snmp ? <Tag tag={d.snmp} /> : '') },
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
                                            message={`The NAS is disabled now and removed at the next FreeRADIUS restart; after that its requests are rejected. ${d.online} subscriber${d.online === 1 ? ' is' : 's are'} online on it.`}
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
