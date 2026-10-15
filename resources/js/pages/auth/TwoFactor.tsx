import { useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import TwoFactorSetup from '@/components/TwoFactorSetup';
import { AuthCard, AuthLayout, CancelSignIn } from '@/layouts/AuthLayout';
import { useShared } from '@/lib/auth';

/** Sign-in step 2: either the code challenge or (setup !== null) mandatory authenticator enrolment. */
export default function TwoFactor({ setup }: { setup: { secret: string; uri: string } | null }) {
    const { flash } = useShared();
    const form = useForm({ code: '' });
    const failed = flash?.type === 'danger';

    const intro = setup ? (
        <>
            <h1>Set up two-factor authentication</h1>
            <p>Your account now requires an authenticator app before you can continue.</p>
            <div className="login-points"><span><Icon name="shield" />Required by admin</span><span><Icon name="lock" />One-time setup</span></div>
        </>
    ) : (
        <>
            <h1>Two-factor check</h1>
            <p>Enter the 6-digit code from your authenticator app to finish signing in.</p>
            <div className="login-points"><span><Icon name="shield" />Extra account protection</span><span><Icon name="lock" />Time-based code</span></div>
        </>
    );

    return (
        <AuthLayout title={setup ? 'Set Up Two-Factor Authentication' : 'Two-Factor Verification'} intro={intro}>
            <AuthCard
                icon="shield"
                heading={setup ? 'Connect your app' : 'Verification code'}
                lead={setup ? 'Add this setup key to your authenticator app, then enter the current 6-digit code.' : 'Open your authenticator app and enter the current code.'}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(setup ? '/two-factor/setup' : '/two-factor', { onFinish: () => form.reset('code') });
                }}
            >
                {setup && <TwoFactorSetup secret={setup.secret} uri={setup.uri} />}
                <label className="login-field">
                    Authenticator code
                    <span className="login-input">
                        <Icon name="lock" />
                        <input
                            name="code"
                            inputMode="numeric"
                            pattern="[0-9]{6}"
                            maxLength={6}
                            autoComplete="one-time-code"
                            placeholder="123456"
                            required
                            autoFocus={!flash}
                            aria-describedby={flash ? 'page-feedback' : undefined}
                            aria-invalid={failed || undefined}
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value.replace(/\D/g, ''))}
                        />
                    </span>
                </label>
                <button className="primary login-submit" disabled={form.processing}>{setup ? 'Enable and continue' : 'Verify'}</button>
                <CancelSignIn />
            </AuthCard>
        </AuthLayout>
    );
}
