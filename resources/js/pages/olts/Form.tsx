import { Link, useForm } from '@inertiajs/react';
import { DeleteButton, PostButton } from '@/components/confirm';
import { Check, ErrorSummary, Field, Panel, Select } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';
import { bind, bindCheck } from '@/lib/form';

type Values = {
    name: string; model: string; host: string; enabled: boolean; snmp_version: string; snmp_port: string; snmp_community: string;
    v3_user: string; v3_context: string; v3_auth_protocol: string; v3_auth_password: string; v3_priv_protocol: string; v3_priv_password: string;
    telnet_enabled: boolean; telnet_port: string; telnet_user: string; telnet_password: string;
};
type Saved = Record<'snmp_community' | 'v3_auth_password' | 'v3_priv_password' | 'telnet_user' | 'telnet_password', boolean>;

export default function OltForm({ id, models, authOptions, privOptions, values, saved }: { id: number | null; models: Record<string, string>; authOptions: Record<string, string>; privOptions: Record<string, string>; values: Values; saved: Saved }) {
    const can = useCan();
    const form = useForm<Values>(values);
    const hint = (on: boolean, otherwise?: string) => (on ? 'Saved. Leave blank to keep it.' : otherwise);
    const clearSecrets = () => form.setData((d) => ({ ...d, snmp_community: '', v3_auth_password: '', v3_priv_password: '', telnet_user: '', telnet_password: '' }));
    return (
        <AppLayout title={id ? 'Edit OLT' : 'New OLT'}>
            <div className="toolbar">
                <Link className="button" href={id ? `/olts/${id}` : '/olts'}>Back</Link>
                {id && <PostButton action={`/olts/${id}/test`} label="Test connection" className="button" />}
                {id && can('danger.delete') && (
                    <DeleteButton action={`/olts/${id}/delete`} label="Remove OLT" title={`Remove ${values.name}?`} message="Its ONU list and client links are removed from the portal. Nothing changes on the OLT." typeToConfirm={values.name} />
                )}
            </div>
            <ErrorSummary errors={form.errors} />
            <form onSubmit={(event) => { event.preventDefault(); form.post(id ? `/olts/${id}/save` : '/olts/save', { preserveScroll: true, onFinish: clearSecrets }); }}>
                <Panel title="OLT">
                    <div className="form-grid">
                        <Field label="Name" required autoComplete="off" hint="Short label, e.g. olt-tirana-01." {...bind(form, 'name')} />
                        <Select label="Model" options={models} {...bind(form, 'model')} />
                        <Field label="Management IP address" required autoComplete="off" inputMode="decimal" {...bind(form, 'host')} />
                        <Check label="Read this OLT" className="check span2" {...bindCheck(form, 'enabled')} />
                    </div>
                </Panel>
                <Panel title="SNMP">
                    <p className="hint">Gives the ONU list, status and signal. Only read requests are sent.</p>
                    <div className="form-grid">
                        <Select label="Version" options={{ '2c': 'SNMP v2c', '3': 'SNMP v3 (most secure)', '1': 'SNMP v1' }} {...bind(form, 'snmp_version')} />
                        <Field label="UDP port" type="number" min={1} max={65535} {...bind(form, 'snmp_port')} />
                        {form.data.snmp_version === '3' ? (
                            <>
                                <Field label="Security name (user)" autoComplete="off" maxLength={32} {...bind(form, 'v3_user')} />
                                <Field label="Context (optional)" autoComplete="off" maxLength={64} {...bind(form, 'v3_context')} />
                                <Select label="Authentication" options={authOptions} {...bind(form, 'v3_auth_protocol')} />
                                <Field label="Authentication password" type="password" autoComplete="new-password" hint={hint(saved.v3_auth_password)} {...bind(form, 'v3_auth_password')} />
                                <Select label="Privacy" options={privOptions} {...bind(form, 'v3_priv_protocol')} />
                                <Field label="Privacy password" type="password" autoComplete="new-password" hint={hint(saved.v3_priv_password)} {...bind(form, 'v3_priv_password')} />
                            </>
                        ) : (
                            <Field label="Read-only community" type="password" autoComplete="new-password" hint={hint(saved.snmp_community, 'Use a read-only community.')} {...bind(form, 'snmp_community')} />
                        )}
                    </div>
                </Panel>
                <Panel title="Telnet">
                    <p className="hint">Gives the MACs behind each ONU, which is how clients are found automatically. The portal can only send its fixed read commands. Telnet is not encrypted, so keep it on your management network, and use a user with few rights.</p>
                    <div className="form-grid">
                        <Check label="Read the MAC table over telnet" className="check span2" {...bindCheck(form, 'telnet_enabled')} />
                        {form.data.telnet_enabled && (
                            <>
                                <Field label="Port" type="number" min={1} max={65535} {...bind(form, 'telnet_port')} />
                                <Field label="User" autoComplete="off" hint={hint(saved.telnet_user)} {...bind(form, 'telnet_user')} />
                                <Field label="Password" type="password" autoComplete="new-password" hint={hint(saved.telnet_password)} {...bind(form, 'telnet_password')} />
                            </>
                        )}
                    </div>
                </Panel>
                <div className="form-actions"><button className="primary" disabled={form.processing}>Save OLT</button></div>
            </form>
        </AppLayout>
    );
}
