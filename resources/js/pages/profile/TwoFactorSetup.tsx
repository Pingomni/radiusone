import { Link, useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import TwoFactorSetup from '@/components/TwoFactorSetup';
import { Field } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

export default function ProfileTwoFactorSetup({ secret, uri }: { secret: string; uri: string }) {
    const form = useForm({ code: '' });
    return (
        <AppLayout title="Set up authenticator">
            <div className="account-settings account-setup">
                <Link className="account-back" href="/profile">← Back to profile settings</Link>
                <section className="account-section" id="account-setup" aria-labelledby="account-setup-heading" tabIndex={-1}>
                    <header className="account-section-header">
                        <span className="account-section-icon"><Icon name="shield" /></span>
                        <div>
                            <h2 id="account-setup-heading">Connect your authenticator</h2>
                            <p>Scan the QR code, then enter a code to confirm your setup.</p>
                        </div>
                    </header>
                    <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/profile/2fa/enable', { onFinish: () => form.reset() }); }}>
                        <div className="span2"><TwoFactorSetup secret={secret} uri={uri} /></div>
                        <Field
                            label="Authenticator code"
                            name="code"
                            required
                            inputMode="numeric"
                            pattern="[0-9]{6}"
                            maxLength={6}
                            autoComplete="one-time-code"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                        />
                        <div className="span2 form-actions">
                            <button className="primary" disabled={form.processing}>Enable 2FA</button>
                            <Link className="button" href="/profile">Cancel</Link>
                        </div>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
