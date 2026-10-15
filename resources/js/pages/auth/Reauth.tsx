import { Link, useForm } from '@inertiajs/react';
import { ErrorSummary, Field, FormActions, Panel } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

/** Recent-reauthentication gate for sensitive actions (password + code when 2FA is on). */
export default function Reauth({ returnPath, needsCode }: { returnPath: string; needsCode: boolean }) {
    const form = useForm({ password: '', code: '' });
    return (
        <AppLayout title="Confirm identity">
            <Panel title="Recent reauthentication required">
                <p>Confirm your identity to return to the originating form. Review it and submit again. Passwords, authentication codes and files must be entered or selected again.</p>
                <ErrorSummary errors={form.errors} />
                <form
                    id="reauth-form"
                    className="form-grid"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/reauth', { onFinish: () => form.reset() });
                    }}
                >
                    <Field
                        label="Current password"
                        name="password"
                        type="password"
                        required
                        autoComplete="current-password"
                        value={form.data.password}
                        error={form.errors.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                    {needsCode && (
                        <Field
                            label="Authenticator code"
                            name="code"
                            required
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            value={form.data.code}
                            error={form.errors.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                        />
                    )}
                    <FormActions>
                        <button className="primary" disabled={form.processing}>Confirm identity</button>
                        <Link className="button" href={returnPath}>Return to form without confirming</Link>
                    </FormActions>
                </form>
            </Panel>
        </AppLayout>
    );
}
