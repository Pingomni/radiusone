import { Link } from '@inertiajs/react';
import { DeleteButton } from '@/components/confirm';
import { DataTable } from '@/components/table';
import { Badge, Panel } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Pool = { id: number; name: string; key: string; ips: number; price: string; enabled: boolean; assigned: number };

export default function Pools({ pools }: { pools: Pool[] }) {
    const can = useCan();
    return (
        <AppLayout title="Static IP Pools">
            {can('services.manage') && (
                <div className="toolbar">
                    <Link className="button primary" href="/services/ip-pools/create">Add IP pool</Link>
                    <Link className="button" href="/clients">Back to clients</Link>
                </div>
            )}
            <Panel title="Static IP Pools">
                <DataTable
                    rows={pools}
                    rowKey={(p) => p.id}
                    exportRows
                    columns={[
                        { header: 'Name', cell: (p) => p.name },
                        { header: 'Key', cell: (p) => p.key },
                        { header: 'IPs', cell: (p) => p.ips },
                        { header: 'Price', cell: (p) => p.price },
                        { header: 'Status', cell: (p) => (p.enabled ? <Badge tone="success">enabled</Badge> : <Badge tone="danger">disabled</Badge>) },
                        { header: 'Assigned', cell: (p) => p.assigned },
                        {
                            header: 'Actions',
                            noExport: true,
                            cell: (p) => can('services.manage') && (
                                <>
                                    <Link href={`/services/ip-pools/${p.id}/edit`}>Edit</Link>{' '}
                                    <DeleteButton action={`/services/ip-pools/${p.id}/delete`} message="Removes the pool definition. Addresses already assigned to clients stay on those clients." title={`Delete IP pool ${p.name}?`} />
                                </>
                            ),
                        },
                    ]}
                />
            </Panel>
        </AppLayout>
    );
}
