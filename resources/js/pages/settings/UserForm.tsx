import { Link, useForm } from '@inertiajs/react';
import { RoleGrid, Sheet, SettingsLinkNav, SettingsRows } from '@/components/settings';
import { Badge, Check, ErrorSummary, Field, Select, Tag, type TagData } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Operator = {
    id: number;
    name: string;
    email: string;
    username: string;
    role: string;
    enabled: boolean;
    lastLogin: string;
    twoFactor: string;
    twoFactorTag: TagData;
};

export default function UserForm({ user, self, roles, roleDescriptions }: {
    user: Operator | null;
    self: boolean;
    roles: Record<string, string>;
    roleDescriptions: { role: string; description: string }[];
}) {
    const form = useForm({
        id: user?.id ?? '',
        name: user?.name ?? '',
        email: user?.email ?? '',
        username: user?.username ?? '',
        role: user?.role ?? 'admin',
        password: '',
        enabled: user?.enabled ?? true,
        two_factor_action: 'keep',
    });
    const submit = (twoFactorAction = 'keep') => {
        form.transform((data) => ({ ...data, two_factor_action: twoFactorAction }));
        form.post('/settings/users/save', { preserveScroll: true, onFinish: () => form.setData('password', '') });
    };

    return (
        <AppLayout title={user ? 'Edit User' : 'New User'}>
            <div className="settings-console">
                <SettingsLinkNav active="users" />
                <Link className="account-back" href="/settings?tab=users">‹ All users</Link>
                <div className="settings-edit-page">
                    <Sheet title={user ? 'Edit user account' : 'New user account'} description="Set contact details, sign-in credentials, and workspace access." icon="user" className="settings-user-form">
                        <ErrorSummary errors={form.errors} />
                        <form className="form-grid compact-form user-edit-form" onSubmit={(event) => { event.preventDefault(); submit(); }}>
                            <Field label="Name" name="name" required value={form.data.name} error={form.errors.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <Field label="Email" name="email" type="email" required value={form.data.email} error={form.errors.email} onChange={(e) => form.setData('email', e.target.value)} />
                            <Field label="Username" name="username" required value={form.data.username} error={form.errors.username} onChange={(e) => form.setData('username', e.target.value)} />
                            <Select
                                label="Role"
                                name={self ? 'role_display' : 'role'}
                                options={roles}
                                value={form.data.role}
                                disabled={self}
                                hint={self ? 'You cannot change your own role.' : undefined}
                                error={form.errors.role}
                                onChange={(e) => form.setData('role', e.target.value)}
                            />
                            <Field
                                label="Password"
                                name="password"
                                type="password"
                                required={!user}
                                autoComplete="new-password"
                                hint={user ? 'Leave blank to keep current password.' : undefined}
                                value={form.data.password}
                                error={form.errors.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                            <Check label="Enabled" name="enabled" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} />
                            {user && (
                                <div className="span2 two-factor-admin">
                                    <div className="two-factor-admin-head">
                                        <div><span>Two-factor authentication</span><strong>{user.twoFactor}</strong></div>
                                        <Tag tag={user.twoFactorTag} />
                                    </div>
                                    <p className="hint">Use these buttons to change only this user's 2FA state. Enabling forces setup after their next password login.</p>
                                    <div className="two-factor-button-row">
                                        <button type="button" className="primary" disabled={form.processing} onClick={() => submit('generate')}>
                                            {user.twoFactor === 'setup required' ? 'Regenerate setup' : 'Enable / require setup'}
                                        </button>
                                        <button type="button" className="danger" disabled={form.processing} onClick={() => submit('disable')}>Disable 2FA</button>
                                    </div>
                                </div>
                            )}
                            <div className="span2 form-actions">
                                <button className="primary" disabled={form.processing}>Save user</button>
                                <Link className="button" href="/settings?tab=users">Cancel</Link>
                            </div>
                        </form>
                    </Sheet>
                    <Sheet title="Account status" description="Current access and sign-in activity." icon="shield" className="settings-user-summary">
                        <SettingsRows
                            rows={[
                                ['User', user?.username ?? 'new'],
                                ['Role', <Badge>{form.data.role}</Badge>],
                                ['Status', !user ? <Badge>new</Badge> : user.enabled ? <Badge tone="success">enabled</Badge> : <Badge tone="danger">disabled</Badge>],
                                ['Two-factor', user ? <Tag tag={user.twoFactorTag} /> : <Badge>off</Badge>],
                                ['Last login', user?.lastLogin ?? '-'],
                            ]}
                        />
                    </Sheet>
                    <Sheet title="Role access" description="Choose the access level that matches this operator’s responsibilities." icon="lock" className="settings-user-roles">
                        <RoleGrid roles={roleDescriptions} compact />
                    </Sheet>
                </div>
            </div>
        </AppLayout>
    );
}
