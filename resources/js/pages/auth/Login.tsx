import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { AuthCard, AuthLayout } from '@/layouts/AuthLayout';
import { useShared } from '@/lib/auth';

export default function Login() {
    const { flash } = useShared();
    const form = useForm({ username: '', password: '' });
    const [visible, setVisible] = useState(false);
    const described = flash ? 'page-feedback' : undefined;

    return (
        <AuthLayout title="Sign in">
            <AuthCard
                heading="Sign in"
                lead=""
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/login', { onFinish: () => form.reset('password') });
                }}
            >
                <label className="login-field">
                    Username
                    <span className="login-input">
                        <Icon name="user" />
                        <input
                            name="username"
                            autoComplete="username"
                            required
                            autoFocus={!flash}
                            aria-describedby={described}
                            value={form.data.username}
                            onChange={(event) => form.setData('username', event.target.value)}
                        />
                    </span>
                </label>
                <label className="login-field" htmlFor="login-password">Password</label>
                <div className="login-input">
                    <Icon name="lock" />
                    <input
                        id="login-password"
                        type={visible ? 'text' : 'password'}
                        name="password"
                        autoComplete="current-password"
                        required
                        aria-describedby={described}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                    <button type="button" className="password-toggle" aria-controls="login-password" aria-pressed={visible} onClick={() => setVisible(!visible)}>
                        {visible ? 'Hide' : 'Show'}
                    </button>
                </div>
                <button className="primary login-submit" disabled={form.processing}>Sign in</button>
                <p className="login-help">No account? Ask your administrator.</p>
            </AuthCard>
        </AuthLayout>
    );
}
