import { Link, router, useForm } from '@inertiajs/react';
import { FlashMessage, TextArea } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';
import { BrandLogo } from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';

type Props = { reason: 'none' | 'invalid' | 'expired'; expires: string | null; url: string; serverId: string | null; canSave: boolean };

/**
 * "Register this server" (RequireRegistration): the console's only page once the grace is over without a licence
 * key. RADIUS and every job keep running; the page says so, links to the free key and takes it here.
 */
export default function Register({ reason, expires, url, serverId, canSave }: Props) {
    const { flash, appName } = useShared();
    const form = useForm({ license_key: '', from: 'register' });
    const expired = reason === 'expired';

    return (
        <AuthLayout title="Register this server">
            <form
                method="post"
                className="login-card register-card"
                onSubmit={(e) => { e.preventDefault(); form.post('/settings/license'); }}
            >
                <div className="login-card-head">
                    <div className="login-brand"><BrandLogo /><strong>{appName}</strong></div>
                    <h1>Register this server</h1>
                    <p>
                        {expired
                            ? `The licence of this server expired on ${expires}. Renew your free licence at account.pingomni.com to use the console again.`
                            : reason === 'invalid'
                                ? 'The licence key saved on this server is not valid. Get a free RadiusOne licence at account.pingomni.com to use the console again.'
                                : 'Every RadiusOne server needs a licence key, also RadiusOne Community: the key is free, from your PingOmni account at account.pingomni.com, and renewed for free every year.'}
                    </p>
                </div>
                {flash && <FlashMessage flash={flash} />}
                <p className="hint">
                    RADIUS, the network queue and scheduled jobs keep running as they are: your subscribers stay online. Only the console waits for the key.
                </p>
                {serverId && <p className="hint">Server ID: <code>{serverId}</code></p>}
                <a className="button primary wide" href={url} target="_blank" rel="noopener">
                    {expired ? 'Renew the free licence' : 'Get a free licence'}
                </a>
                {canSave ? (
                    <>
                        <TextArea
                            label="Licence key"
                            name="license_key"
                            rows={4}
                            placeholder="RADIUSONE1.…"
                            hint="Paste the whole key from your PingOmni account. The console opens as soon as it is saved."
                            value={form.data.license_key}
                            error={form.errors.license_key}
                            onChange={(e) => form.setData('license_key', e.target.value)}
                        />
                        <button className="primary wide" disabled={form.processing || form.data.license_key.trim() === ''}>Save the key</button>
                        <Link className="button wide" href="/settings?tab=license">Settings → License</Link>
                    </>
                ) : (
                    <p className="hint">Ask a workspace administrator to add the key under Settings → License.</p>
                )}
                <button type="button" className="wide" onClick={() => router.post('/logout')}>Sign out</button>
            </form>
        </AuthLayout>
    );
}
