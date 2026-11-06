import { router, useForm } from '@inertiajs/react';
import { type CSSProperties, type ReactNode } from 'react';
import { Sheet } from '@/components/settings';
import { Badge, Check, ErrorSummary, Field, FormActions, Select, TextArea } from '@/components/ui';
import { PayRows } from '@/pages/contracts/Show';
import { SecretField } from '@/settings/fields';
import type { PaySettings } from '@/settings/types';

type Mode = 'test' | 'live';

const MODES: Record<Mode, string> = { test: 'Test: no real money', live: 'Live: real payments' };

/** One service's test and live credentials, saved apart, and the switch for which one the pay page uses. */
function Service({ name, title, mode, saved, onMode, enabled, onEnabled, licensed, children, note }: {
    name: 'pok' | 'stripe'; title: string; mode: Mode; saved: Record<string, boolean>; onMode: (m: Mode) => void;
    enabled: boolean; onEnabled: (on: boolean) => void; licensed: boolean; children: (m: Mode) => ReactNode; note?: ReactNode;
}) {
    return (
        <Sheet title={title} icon="lock" meta={<Badge tone={mode === 'live' ? 'success' : 'warning'}>{mode === 'live' ? 'Live' : 'Test mode'}</Badge>}>
            <div className="form-grid">
                <Check className="check span2" label={`Offer ${title}`} checked={enabled} disabled={!licensed} onChange={(e) => onEnabled(e.target.checked)} />
                <Select label="The pay page uses" name={`${name}_mode`} options={MODES} value={mode} onChange={(e) => onMode(e.target.value as Mode)}
                    hint={mode === 'test' ? 'Test payments are recorded under Online payments, but never pay an invoice or renew a service.' : 'Customers pay real money, and each payment renews their service.'} />
                {note}
                {(['test', 'live'] as Mode[]).map((m) => (
                    <fieldset key={m} className="span2 pay-credentials">
                        <legend>{m === 'test' ? 'Test credentials' : 'Live credentials'}{m === mode ? ' (in use)' : ''}</legend>
                        <div className="form-grid">
                            {children(m)}
                            <FormActions>
                                <button type="button" className="button" disabled={!saved[`${name}_${m}`]} onClick={() => router.post(`/settings/pay/test/${name}/${m}`, {}, { preserveScroll: true })}>
                                    Test the saved {m} key
                                </button>
                            </FormActions>
                        </div>
                    </fieldset>
                ))}
            </div>
        </Sheet>
    );
}

export function PayTab({ p }: { p: PaySettings }) {
    const secrets = { pok_test_key_secret: '', pok_live_key_secret: '', stripe_test_secret_key: '', stripe_live_secret_key: '' };
    const form = useForm({ ...p.values, enabled: p.values.enabled === '1', pok_enabled: p.values.pok_enabled === '1', stripe_enabled: p.values.stripe_enabled === '1', ...secrets });
    const errors = form.errors as Record<string, string | undefined>;
    const defaults = p.defaults[form.data.language] ?? p.defaults.en;
    type Text = Exclude<keyof typeof form.data, 'enabled' | 'pok_enabled' | 'stripe_enabled'>;
    const set = (key: Text, value: string) => form.setData(key, value);
    const preview = { '--pay-color': form.data.color } as CSSProperties;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/pay', { preserveScroll: true, onSuccess: () => form.reset(...(Object.keys(secrets) as Text[])) }); }}>
            <ErrorSummary errors={errors} />
            <Sheet title="Pay page" icon="billing">
                <div className="form-grid">
                    <Check className="check span2" label="Turn on the pay page" checked={form.data.enabled} disabled={!p.licensed} onChange={(e) => form.setData('enabled', e.target.checked)} />
                    <Field label="Address" name="host" placeholder={p.suggestedHost} value={form.data.host} hint={`Point this name at this server, then run on the server: sudo radiusone-pay-domain ${form.data.host || p.suggestedHost}`} onChange={(e) => set('host', e.target.value)} />
                    <Select label="Language of the page" name="language" options={p.languages} value={form.data.language} onChange={(e) => set('language', e.target.value)} />
                    <p className="span2 hint">Customers find their account with their username and the last 4 digits of the phone number saved on it. Online payment can be turned off for a client on their page.</p>
                </div>
            </Sheet>
            <Sheet title="How it looks" icon="image">
                <div className="form-grid">
                    <Field label="Title" name="title" maxLength={80} placeholder={defaults.title} value={form.data.title} onChange={(e) => set('title', e.target.value)} />
                    <Field label="Colour" name="color" type="color" className="pay-color-field" value={form.data.color} hint="Your company's colour: the buttons and the top of the page." onChange={(e) => set('color', e.target.value)} />
                    <TextArea label="Description" name="intro" rows={3} maxLength={400} placeholder={defaults.intro} value={form.data.intro} onChange={(e) => set('intro', e.target.value)} />
                    <TextArea label="Footer" name="footer" rows={3} maxLength={400} value={form.data.footer} hint="For example your phone number and office hours." onChange={(e) => set('footer', e.target.value)} />
                    <div className="span2 pay-brand-preview" style={preview}>
                        {p.logo ? <img src={p.logo} alt="" /> : <span>No logo yet: add one under Settings → Branding.</span>}
                        <span className="pay-swatch">Pay</span>
                    </div>
                </div>
            </Sheet>
            <Service name="pok" title="POK" mode={form.data.pok_mode as Mode} saved={p.saved} onMode={(m) => set('pok_mode', m)} enabled={form.data.pok_enabled} onEnabled={(on) => form.setData('pok_enabled', on)} licensed={p.licensed}
                note={<p className="span2 hint">Test uses POK&apos;s staging service (api-staging.pokpay.io) with its staging keys; live uses production (api.pokpay.io). Both come from your POK merchant account.</p>}>
                {(m) => (
                    <>
                        <Field label="Merchant id" name={`pok_${m}_merchant_id`} autoComplete="off" value={form.data[`pok_${m}_merchant_id`]} onChange={(e) => set(`pok_${m}_merchant_id`, e.target.value)} />
                        <Field label="API key id" name={`pok_${m}_key_id`} autoComplete="off" value={form.data[`pok_${m}_key_id`]} onChange={(e) => set(`pok_${m}_key_id`, e.target.value)} />
                        <SecretField label="API key secret" name={`pok_${m}_key_secret`} saved={p.saved[`pok_${m}`]} value={form.data[`pok_${m}_key_secret`]} onChange={(v) => set(`pok_${m}_key_secret`, v)} />
                    </>
                )}
            </Service>
            <Service name="stripe" title="Stripe" mode={form.data.stripe_mode as Mode} saved={p.saved} onMode={(m) => set('stripe_mode', m)} enabled={form.data.stripe_enabled} onEnabled={(on) => form.setData('stripe_enabled', on)} licensed={p.licensed}
                note={<>
                    <p className="span2 hint">Stripe opens accounts only in the countries it supports, and Albania is not one of them. Check stripe.com/global for your company&apos;s country.</p>
                    <p className="span2 hint">Optional: in Stripe, add a webhook to https://{p.webhookUrl}/pay/webhook for the checkout.session events, so payments show at once. Without it, they are checked every 5 minutes.</p>
                </>}>
                {(m) => (
                    <SecretField label="Secret key" name={`stripe_${m}_secret_key`} saved={p.saved[`stripe_${m}`]} value={form.data[`stripe_${m}_secret_key`]} hint={`Starts with sk_${m}_. The publishable key is not used.`} onChange={(v) => set(`stripe_${m}_secret_key`, v)} />
                )}
            </Service>
            <Sheet title="Save" icon="settings">
                <FormActions className="form-actions"><button className="button primary" type="submit" disabled={form.processing || !p.licensed}>Save</button></FormActions>
            </Sheet>
            <Sheet title="Latest online payments" icon="reports" meta={<a href="/payments">All online payments</a>}><PayRows rows={p.payments} username /></Sheet>
        </form>
    );
}
