import { router, useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm';
import { SettingsRows, Sheet } from '@/components/settings';
import { Badge, TextArea } from '@/components/ui';
import type { License } from '@/settings/types';

const STATE: Record<License['state'], [string, string]> = {
    none: ['Community', ''],
    active: ['Pro', 'success'],
    expired: ['Pro, expired', 'warning'],
    invalid: ['Key not valid', 'danger'],
};

/** Settings → License: the RadiusOne Pro key, what it turns on, and adding, replacing or removing it. */
export function LicenseTab({ l }: { l: License }) {
    const form = useForm({ license_key: '' });
    const ask = useConfirm();
    const [label, tone] = STATE[l.state];
    const licensed = l.state === 'active' || l.state === 'expired';

    return (
        <>
            <Sheet title="License" icon="lock" meta={<Badge tone={tone}>{label}</Badge>}>
                {licensed ? (
                    <SettingsRows rows={[
                        ['Licensed to', l.customer || '-'],
                        ['Valid until', l.state === 'expired' ? `${l.expires} (expired: Pro keeps running as it is, but cannot be changed)` : (l.expires ?? '-')],
                        ['Licence id', l.id || '-'],
                    ]} />
                ) : (
                    <p className="panel-text">
                        This is RadiusOne Community: clients, services, NAS devices, RADIUS, the network queue, reports and local backups.
                        A RadiusOne Pro key from your PingOmni account (account.pingomni.com) turns on the features below.
                    </p>
                )}
                <div className="settings-list settings-sheet-list">
                    {Object.entries(l.names).map(([feature, name]) => (
                        <div key={feature}><span>{name}</span><strong>{l.features[feature] ? <Badge tone="success">On</Badge> : <Badge>Pro</Badge>}</strong></div>
                    ))}
                </div>
            </Sheet>
            <Sheet title={licensed ? 'Replace the key' : 'Add a licence key'} icon="lock">
                <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/settings/license', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                    <TextArea
                        label="Licence key"
                        name="license_key"
                        rows={4}
                        placeholder="RADIUSONE1.…"
                        hint="Paste the whole key. It is checked on this server; nothing is sent anywhere. After a renewal, paste the new key here."
                        value={form.data.license_key}
                        error={form.errors.license_key}
                        onChange={(e) => form.setData('license_key', e.target.value)}
                    />
                    <div className="span2 form-actions">
                        <button className="primary" disabled={form.processing || form.data.license_key.trim() === ''}>Save the key</button>
                        {licensed && (
                            <button type="button" onClick={async () => {
                                const ok = await ask({
                                    title: 'Remove the licence?',
                                    message: 'RadiusOne becomes Community: billing, notifications, NAS monitoring, remote backups and your logo turn off. Their data is kept, and comes back with a key.',
                                    confirmLabel: 'Remove',
                                });
                                if (ok) router.post('/settings/license', { license_key: '' }, { preserveScroll: true });
                            }}>Remove the licence</button>
                        )}
                    </div>
                </form>
            </Sheet>
        </>
    );
}
