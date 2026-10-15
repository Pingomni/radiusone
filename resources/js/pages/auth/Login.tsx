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
        <AuthLayout
            title="Login"
            intro={
                <>
                    <div className="login-story">
                        <span className="login-eyebrow">YOUR OPERATIONS, CONNECTED</span>
                        <h2>More clarity.<br />Better connections.</h2>
                        <p>A thoughtful workspace for your customers, network and everyday operations.</p>
                    </div>
                    <div className="login-features">
                        <div><Icon name="clients" /><span><strong>Customers, in focus</strong><small>Accounts, services and documents together.</small></span></div>
                        <div><Icon name="billing" /><span><strong>Billing, simplified</strong><small>A clear view of invoices and payments.</small></span></div>
                        <div><Icon name="signal" /><span><strong>Your network, connected</strong><small>Devices and accounting in one workspace.</small></span></div>
                    </div>
                    <div className="login-intro-footer">Built for the people behind the connection.</div>
                </>
            }
        >
            <AuthCard
                icon="lock"
                eyebrow="WELCOME BACK"
                heading="Sign in to your workspace"
                lead="Enter your operator credentials to continue."
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
                            placeholder="Enter your username"
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
                        placeholder="Enter your password"
                        required
                        aria-describedby={described}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                    <button type="button" className="password-toggle" aria-controls="login-password" aria-pressed={visible} onClick={() => setVisible(!visible)}>
                        {visible ? 'Hide' : 'Show'}
                    </button>
                </div>
                <button className="primary login-submit" disabled={form.processing}>Sign in <span aria-hidden="true">&rarr;</span></button>
                <p className="login-help">Need access? Contact your workspace administrator.</p>
                <div className="login-security"><Icon name="shield" /><span>Secure operator access</span></div>
            </AuthCard>
        </AuthLayout>
    );
}
