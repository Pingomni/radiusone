import { Link, useForm } from '@inertiajs/react';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { PasswordField, Sheet, SettingsRows } from '@/components/settings';
import { Badge, Field, Tag, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Profile = { name: string; email: string; username: string; role: string; enabled: boolean; lastLogin: string };
type TwoFactor = { state: 'enabled' | 'pending' | 'off'; required: boolean; tag: TagData };

function AccountForm({ profile }: { profile: Profile }) {
    const form = useForm({ name: profile.name, email: profile.email });
    return (
        <form className="form-grid" onSubmit={(event) => { event.preventDefault(); form.post('/profile/save', { preserveScroll: true }); }}>
            <Field label="Full name" name="name" required maxLength={120} autoComplete="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
            <Field label="Email address" name="email" type="email" required maxLength={190} autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
            <p className="span2 hint">Your username and access role are managed by an administrator.</p>
            <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Save changes</button></div>
        </form>
    );
}

function PasswordForm() {
    const form = useForm({ current_password: '', new_password: '', new_password_confirm: '' });
    return (
        <form
            className="form-grid"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/profile/password', { preserveScroll: true, onFinish: () => form.reset() });
            }}
        >
            <div className="span2 profile-current-password">
                <PasswordField label="Current password" name="current_password" autoComplete="current-password" value={form.data.current_password} onChange={(v) => form.setData('current_password', v)} />
            </div>
            <PasswordField label="New password" name="new_password" autoComplete="new-password" hint="Use at least 10 characters." value={form.data.new_password} onChange={(v) => form.setData('new_password', v)} />
            <PasswordField label="Confirm new password" name="new_password_confirm" autoComplete="new-password" value={form.data.new_password_confirm} onChange={(v) => form.setData('new_password_confirm', v)} />
            <p className="span2 hint">Changing your password signs you out of all sessions.</p>
            <div className="span2 form-actions"><button disabled={form.processing}>Update password</button></div>
        </form>
    );
}

/** Turning two-factor off takes a current code from the authenticator, not just the signed-in session. */
function DisableTwoFactorForm() {
    const form = useForm({ code: '' });
    return (
        <form onSubmit={(event) => { event.preventDefault(); form.post('/profile/2fa/disable', { preserveScroll: true, onFinish: () => form.reset() }); }}>
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
            <div className="form-actions"><button className="danger" disabled={form.processing}>Disable two-factor</button></div>
        </form>
    );
}

function TwoFactorPanel({ twoFactor }: { twoFactor: TwoFactor }) {
    const enabled = twoFactor.state === 'enabled';
    const pending = twoFactor.state === 'pending';
    const status = enabled ? 'Enabled' : pending ? 'Setup incomplete' : 'Not enabled';
    const description = enabled
        ? 'You’ll be asked for a code from your authenticator app when you sign in.'
        : pending ? 'Finish verifying your authenticator app to turn on two-factor protection.' : 'Add a second step at sign-in with a code from your authenticator app.';
    let action;
    if (enabled && twoFactor.required) {
        action = <p className="hint">Your role requires two-factor authentication.</p>;
    } else if (enabled) {
        action = <DisableTwoFactorForm />;
    } else if (pending) {
        action = <Link className="button primary" href="/profile/2fa/setup">Continue setup</Link>;
    } else {
        action = <PostButton action="/profile/2fa/start" className="primary" label="Set up authenticator" />;
    }
    return (
        <Sheet title="Two-factor authentication" description="An extra layer of protection for your account." icon="shield">
            <div className="account-two-factor">
                <div>
                    <span className={`account-status ${enabled ? 'is-enabled' : 'is-warning'}`}><Icon name="shield" />{status}</span>
                    <p>{description}</p>
                </div>
                {action}
            </div>
        </Sheet>
    );
}

export default function ProfilePage({ profile, twoFactor }: { profile: Profile; twoFactor: TwoFactor }) {
    return (
        <AppLayout title="Profile settings">
            <div className="settings-console profile-settings">
                <div className="settings-edit-page">
                    <div className="profile-settings-main">
                        <Sheet title="User account" description="Keep your name and contact details up to date." icon="user">
                            <AccountForm profile={profile} />
                        </Sheet>
                        <Sheet title="Password" description="Use a unique password to protect access to your workspace." icon="lock">
                            <PasswordForm />
                        </Sheet>
                        <TwoFactorPanel twoFactor={twoFactor} />
                    </div>
                    <Sheet title="Account status" description="Current access and sign-in activity." icon="shield" className="settings-user-summary">
                        <SettingsRows
                            rows={[
                                ['User', profile.username],
                                ['Role', <Badge>{profile.role}</Badge>],
                                ['Status', profile.enabled ? <Badge tone="success">enabled</Badge> : <Badge tone="danger">disabled</Badge>],
                                ['Two-factor', <Tag tag={twoFactor.tag} />],
                                ['Last login', profile.lastLogin],
                            ]}
                        />
                    </Sheet>
                </div>
            </div>
        </AppLayout>
    );
}
