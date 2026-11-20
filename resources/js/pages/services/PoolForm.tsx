import { Link, useForm } from '@inertiajs/react';
import { Check, ErrorSummary, Field, Panel, TextArea } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';
import { bind, bindCheck } from '@/lib/form';

type PoolValues = { id: number | ''; name: string; pool_key: string; ip_ranges: string; price: string; currency: string; description: string; enabled: boolean };

export default function PoolForm({ pool, isNew }: { pool: PoolValues; isNew: boolean }) {
    const form = useForm<PoolValues>(pool);
    const { currency } = useShared();
    return (
        <AppLayout title={isNew ? 'Add IP pool' : 'Edit IP pool'}>
            <Panel title="Static IP pool">
                <ErrorSummary errors={form.errors} />
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/services/ip-pools/save', { preserveScroll: true }); }}>
                    <Field label="Pool name" required {...bind(form, 'name')} />
                    <Field label="Pool key" {...bind(form, 'pool_key')} />
                    <TextArea label="IP addresses / ranges" hint="One per line or comma separated. Ranges (10.0.0.10-20) and /24 to /32 work." {...bind(form, 'ip_ranges')} />
                    <Field label={`Static IP price (${currency})`} type="number" step="0.01" min="0" {...bind(form, 'price')} />
                    <TextArea label="Description" {...bind(form, 'description')} />
                    <Check label="Enabled" {...bindCheck(form, 'enabled')} />
                    <div className="span2 form-actions">
                        <button className="primary" disabled={form.processing}>Save IP pool</button>
                        <Link className="button" href="/services/ip-pools">Cancel</Link>
                    </div>
                </form>
            </Panel>
        </AppLayout>
    );
}
