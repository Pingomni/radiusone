import { Link } from '@inertiajs/react';
import { DeleteButton } from '@/components/confirm';
import { DataTable } from '@/components/table';
import { Badge, Panel } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Service = { id: number; name: string; group: string; download: string; upload: string; expired: string; price: string; cap: string; enabled: boolean; clients: number };

function DeleteControl({ s }: { s: Service }) {
    if (s.clients > 0) {
        // The repository refuses this delete anyway; say why up front instead of after a click.
        return <span className="muted-action" title="To delete this plan, move or archive its clients first.">In use by {s.clients} {s.clients === 1 ? 'client' : 'clients'}</span>;
    }
    return (
        <DeleteButton
            action={`/services/${s.id}/delete`}
            message={`Removes the plan and its RADIUS group attributes (${s.group}). No clients use it. This cannot be undone.`}
            typeToConfirm={s.group}
            title={`Delete plan ${s.name || s.group}?`}
        />
    );
}

export default function Services({ services }: { services: Service[] }) {
    const can = useCan();
    return (
        <AppLayout title="Services">
            {can('services.manage') && (
                <div className="toolbar">
                    <Link className="button" href="/services/ip-pools">Manage IP pools</Link>
                    <Link className="button primary" href="/services/create">New service plan</Link>
                </div>
            )}
            <Panel title="Internet Service Plans">
                <DataTable
                    rows={services}
                    rowKey={(s) => s.id}
                    exportRows
                    columns={[
                        { header: 'Name', cell: (s) => s.name },
                        { header: 'Download', cell: (s) => s.download },
                        { header: 'Upload', cell: (s) => s.upload },
                        { header: 'Expired service', cell: (s) => s.expired },
                        { header: 'Price', cell: (s) => s.price },
                        { header: 'Data cap', cell: (s) => s.cap },
                        { header: 'Status', cell: (s) => (s.enabled ? <Badge tone="success">enabled</Badge> : <Badge tone="danger">disabled</Badge>) },
                        { header: 'Clients', cell: (s) => s.clients },
                        {
                            header: 'Actions',
                            noExport: true,
                            cell: (s) => <><Link href={`/services/${s.id}/edit`}>{can('services.manage') ? 'Edit' : 'View'}</Link> {can('danger.delete') && <DeleteControl s={s} />}</>,
                        },
                    ]}
                />
            </Panel>
        </AppLayout>
    );
}
