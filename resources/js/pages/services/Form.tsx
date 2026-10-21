import { Link, useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm';
import { Check, ErrorSummary, Field, Panel, Select, TextArea } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';
import { bind, bindCheck } from '@/lib/form';

type ServiceValues = Record<string, string | number | boolean> & { id: number | ''; group_name: string; name: string; enabled: boolean };

export default function ServiceForm({ service, clientCount, expiredOptions, capOptions, canManage }: {
    service: ServiceValues;
    clientCount: number | null;
    expiredOptions: Record<string, string>;
    capOptions: Record<string, string>;
    canManage: boolean;
}) {
    const form = useForm<ServiceValues>(service);
    const ask = useConfirm();
    const disabled = !canManage;
    const { currency } = useShared();
    const field = (label: string, key: string, props: Record<string, unknown> = {}) => (
        <Field label={label} disabled={disabled} {...bind(form, key)} {...props} />
    );

    return (
        <AppLayout title={service.id ? 'Edit Service' : 'New Service'}>
            <Panel title="Service Plan">
                <ErrorSummary errors={form.errors} />
                <form
                    className="form-grid"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        if (service.id && form.data.group_name !== service.group_name) {
                            const ok = await ask({ title: 'Rename RADIUS group?', message: 'Renaming the group moves every client and invoice on this plan to the new name.', confirmLabel: 'Rename and save' });
                            if (!ok) return;
                        }
                        form.post('/services/save', { preserveScroll: true });
                    }}
                >
                    {field('Name', 'name', { required: true })}
                    {field('RADIUS group name', 'group_name', {
                        required: true,
                        hint: clientCount !== null ? `Changing this renames the RADIUS group for all ${clientCount} clients on this plan.` : undefined,
                    })}
                    <TextArea label="Description" disabled={disabled} {...bind(form, 'description')} />
                    <Check label="Enabled" disabled={disabled} {...bindCheck(form, 'enabled')} />
                    {field('Customer download (Mbps)', 'download_speed')}
                    {field('Customer upload (Mbps)', 'upload_speed')}
                    <Select label="IP behavior" disabled={disabled} options={{ pool: 'Dynamic pool', static: 'Static per client', none: 'No IP attribute' }} {...bind(form, 'ip_mode')} />
                    {field('IP pool name', 'ip_pool')}
                    {field('Session timeout', 'session_timeout', { type: 'number' })}
                    {field('Idle timeout', 'idle_timeout', { type: 'number' })}
                    {field('Simultaneous use default', 'simultaneous_use', { type: 'number' })}
                    {field('Expiration behavior', 'expiration_behavior')}
                    <Select label="Billing mode" hint="Metadata only; renewal always needs full payment." disabled={disabled} options={{ prepaid: 'prepaid', postpaid: 'postpaid' }} {...bind(form, 'billing_mode')} />
                    <Select label="Expired service" disabled={disabled} options={expiredOptions} {...bind(form, 'expired_service_group')} />
                    {field(`Price (${currency})`, 'price', { type: 'number', step: '0.01', hint: 'Currency is set in Settings → Regional.' })}
                    <h3 className="form-section-title span2">Data cap</h3>
                    {field('Data cap per cycle (GB)', 'total_quota', { type: 'number', step: 'any', min: '0', hint: 'Download plus upload, per customer, until their expiry date. Leave blank for no cap.' })}
                    <Select
                        label="When the cap is reached"
                        disabled={disabled || String(form.data.total_quota ?? '') === ''}
                        options={{ notify: 'Notify only', throttle: 'Slow down to another plan' }}
                        hint="Customers are told at 80% and 100% (Settings → Notifications templates)."
                        {...bind(form, 'cap_action')}
                    />
                    {form.data.cap_action === 'throttle' && String(form.data.total_quota ?? '') !== '' && (
                        <Select label="Slow-down plan" disabled={disabled} required placeholder="Choose a plan" options={capOptions} hint="Until the next cycle starts." {...bind(form, 'cap_service_group')} />
                    )}
                    <details className="span2">
                        <summary>Advanced legacy RADIUS attributes</summary>
                        <p>Explicit access adapters ignore these attributes. Routine provisioning uses the plan fields above.</p>
                        <TextArea label="Custom group attributes" disabled={disabled} {...bind(form, 'custom_group_attrs')} />
                    </details>
                    <div className="span2 form-actions">
                        {canManage && <button className="primary" disabled={form.processing}>Save service</button>}
                        <Link className="button" href="/services">{canManage ? 'Cancel' : 'Back'}</Link>
                    </div>
                </form>
            </Panel>
        </AppLayout>
    );
}
