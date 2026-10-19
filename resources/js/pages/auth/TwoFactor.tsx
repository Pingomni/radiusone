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

    return (
        <AuthLayout title={setup ? 'Set Up Two-Factor Authentication' : 'Two-Factor Verification'}>
            <AuthCard
                heading={setup ? 'Set up two-factor' : 'Two-factor check'}
                lead={setup ? 'Your account requires an authenticator app. Add this setup key to your authenticator app, then enter the current 6-digit code.' : 'Enter the 6-digit code from your authenticator app to finish signing in.'}
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
