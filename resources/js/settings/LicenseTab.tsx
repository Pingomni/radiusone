import { router, useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm';
import { SettingsRows, Sheet } from '@/components/settings';
import { TextArea, State } from '@/components/ui';
import type { License } from '@/settings/types';

const STATE: Record<License['state'], [string, string]> = {
    none: ['Community, not registered', 'warning'],
    active: ['Pro', 'success'],
    expired: ['Pro, expired', 'warning'],
    invalid: ['Key not valid', 'danger'],
};

/** The free key: RadiusOne Community, registered. */
const COMMUNITY: Partial<Record<License['state'], [string, string]>> = {
    active: ['Community (registered)', 'success'],
    expired: ['Community, expired', 'warning'],
};

/** Whether the console needs a key (Registration), for the License tab. */
function registrationRow(l: License): string {
    const r = l.registration;
    const days = r.days_left === 1 ? '1 day' : `${r.days_left} days`;
    if (r.state === 'registered') {
        return r.reason === 'expired' ? `Registered for ${days} more: renew the licence at account.pingomni.com` : 'Registered';
    }
    if (r.state === 'grace') {
        return `Not registered: the console asks for a key in ${days}. RADIUS and every job keep running regardless.`;
    }
    return 'Not registered: the console opens only this tab until a key is saved. RADIUS and every job keep running.';
}

function accountRow(a: License['activation']): string {
    const when = a.last_ok ? a.last_ok.replace('T', ' ').slice(0, 16) : '';
    switch (a.status) {
        case 'registered': return `Registered as ${a.hostname ?? 'this server'}, last check-in ${when}`;
        case 'pending': return 'Registering with your PingOmni account (within five minutes)';
        case 'unreachable': return `Not registered yet: ${a.message} Retried every hour.`;
        case 'in_use':
        case 'refused': return `Not registered: ${a.message}`;
        default: return 'Off (RADIUSONE_ACCOUNT_URL is empty)';
    }
}

/** Settings → License: the licence key (RadiusOne Community's free key or a Pro key), what it turns on, and adding, replacing or removing it. */
export function LicenseTab({ l }: { l: License }) {
    const form = useForm({ license_key: '' });
    const ask = useConfirm();
    const community = l.edition === 'community';
    const [label, tone] = (community ? COMMUNITY[l.state] : undefined) ?? STATE[l.state];
    const licensed = l.state === 'active' || l.state === 'expired';
    const full = l.clientLimit !== null && l.clientCount >= l.clientLimit;
    const clients = l.clientLimit === null
        ? `${l.clientCount.toLocaleString()} (no limit)`
        : `${l.clientCount.toLocaleString()} of ${l.clientLimit.toLocaleString()}${full ? ': new clients are refused until clients are archived or the licence covers more' : ''}`;

    return (
        <>
            <Sheet title="License" icon="lock" meta={<State tone={tone}>{label}</State>}>
                {licensed ? (
                    <SettingsRows rows={[
                        ['Licensed to', l.customer || '-'],
                        ['Edition', community ? 'RadiusOne Community (registered)' : 'RadiusOne Pro'],
                        ['Valid until', l.state === 'expired'
                            ? `${l.expires} (expired: ${community ? 'renew the free licence at account.pingomni.com' : 'Pro keeps running as it is, but cannot be changed'})`
                            : (l.expires ?? '-')],
                        ['Licence id', l.id || '-'],
                        ['Clients', clients],
                        ['Registration', registrationRow(l)],
                        ['PingOmni account', accountRow(l.activation)],
                    ]} />
                ) : (
                    <>
                        <p className="panel-text">
                            This is RadiusOne Community: clients, services, NAS devices, RADIUS, the network queue, reports and local backups,
                            for up to {l.clientLimit?.toLocaleString()} clients. Every server needs a licence key, also Community, whose key is free:
                            get it from your PingOmni account (account.pingomni.com) and paste it below. A RadiusOne Pro key turns on the features
                            below, for up to 999 clients (Licence 1), 4,999 (Licence 2) or with no limit (Licence 3).
                        </p>
                        <SettingsRows rows={[['Clients', clients], ['Registration', registrationRow(l)]]} />
                        <div className="form-actions">
                            <a className="button primary" href={l.registerUrl} target="_blank" rel="noopener">Get a free licence</a>
                        </div>
                    </>
                )}
                <div className="settings-list settings-sheet-list">
                    {Object.entries(l.names).map(([feature, name]) => (
                        <div key={feature}><span>{name}</span><strong>{l.features[feature] ? <State tone="success">On</State> : <State>Pro</State>}</strong></div>
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
                        hint="The whole key from your PingOmni account. Renewals are picked up by themselves."
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
                                    message: community
                                        ? 'This server is no longer registered: the web console asks for a key again. RADIUS and every job keep running.'
                                        : 'RadiusOne becomes Community: billing, notifications, NAS monitoring, remote backups and your logo turn off. Their data is kept, and comes back with a key. Without a key the web console asks for a free Community key.',
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
