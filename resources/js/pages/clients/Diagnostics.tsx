import { Link, useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm';
import { DataTable } from '@/components/table';
import { Panel, Select } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { bind } from '@/lib/form';

type Operation = { id: number; action: string; revision: string; state: string; attempts: number; result: string };

function RestrictionForm({ username, sources }: { username: string; sources: Record<string, string> }) {
    const form = useForm({ action: 'suspend', source: 'manual' });
    const ask = useConfirm();
    return (
        <form
            className="inline-form"
            onSubmit={async (event) => {
                event.preventDefault();
                const ok = await ask({
                    title: `Change service restriction for ${username}?`,
                    message: 'Suspend blocks authentication until the restriction is lifted; restore lifts a manual suspension. The change is queued to the network and recorded in the audit log.',
                    confirmLabel: 'Save restriction',
                });
                if (ok) form.post(`/clients/${encodeURIComponent(username)}/suspension`);
            }}
        >
            <Select label="Service action" options={{ suspend: 'Suspend service', restore: 'Restore manual suspension' }} {...bind(form, 'action')} />
            <Select label="Reason" options={sources} {...bind(form, 'source')} />
            <button className="danger" disabled={form.processing}>Save service restriction</button>
        </form>
    );
}

export default function Diagnostics({ username, signals, operations, sources }: {
    username: string;
    signals: { signal: string; evidence: string }[];
    operations: Operation[];
    sources: Record<string, string>;
}) {
    const can = useCan();
    return (
        <AppLayout title="Connection diagnostics">
            <div className="toolbar"><Link className="button" href={`/clients/${encodeURIComponent(username)}/edit`}>Back to customer</Link></div>
            <Panel title="Connection diagnostics">
                <DataTable rows={signals} rowKey={(r) => r.signal} perPage={100} columns={[{ header: 'Signal', cell: (r) => r.signal }, { header: 'Evidence', cell: (r) => r.evidence }]} />
            </Panel>
            <Panel title="Network operations">
                <DataTable
                    rows={operations}
                    rowKey={(o) => o.id}
                    columns={[
                        { header: 'ID', cell: (o) => o.id },
                        { header: 'Action', cell: (o) => o.action },
                        { header: 'Revision', cell: (o) => o.revision },
                        { header: 'State', cell: (o) => o.state },
                        { header: 'Attempts', cell: (o) => o.attempts },
                        { header: 'Result', cell: (o) => o.result },
                    ]}
                />
            </Panel>
            <Panel title="Service restriction">
                {can('clients.suspend') && <RestrictionForm username={username} sources={sources} />}
            </Panel>
        </AppLayout>
    );
}
