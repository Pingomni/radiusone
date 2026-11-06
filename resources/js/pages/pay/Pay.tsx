import { Head, router, useForm } from '@inertiajs/react';
import { useState, type CSSProperties } from 'react';
import { FlashMessage } from '@/components/ui';
import { useShared } from '@/lib/auth';

type Labels = Record<string, string>;
type PageData = { title: string; intro: string; footer: string; color: string; colorText: string; company: string; labels: Labels; language: string; providers: { id: string; name: string }[]; testing: boolean };
type Option = { period: string; months: number; start: string; end: string; amount: string; currency: string };
type Account = { username: string; customer: string; service: string; paidUntil: string; contractUntil: string; options: Option[] };
type State = { status: string; test: boolean; invoice: string | null; until: string | null };

function Result({ state, l }: { state: State; l: Labels }) {
    const [title, detail] = state.status === 'paid' && state.test ? [l.test_paid, l.test_paid_detail]
        : state.status === 'paid' ? [l.paid, `${l.paid_detail} ${state.until}.`]
        : state.status === 'mismatch' ? [l.review, l.review_detail]
        : state.status === 'waiting' ? [l.waiting, l.waiting_detail]
        : [l.canceled, l.canceled_detail];
    return (
        <div className="pay-result" data-status={state.status} role="status">
            <h2>{title}</h2>
            <p>{detail}</p>
            {state.invoice && <p className="pay-muted">{l.invoice}: {state.invoice}</p>}
            {state.status === 'waiting' && <button type="button" className="pay-button" onClick={() => router.reload()}>{l.refresh}</button>}
            <p><a href="/pay">{l.back}</a></p>
        </div>
    );
}

function Periods({ account, l, providers }: { account: Account; l: Labels; providers: PageData['providers'] }) {
    const [period, setPeriod] = useState(account.options[0]?.period ?? '');
    const [provider, setProvider] = useState(providers[0]?.id ?? '');
    const form = useForm({ period, provider });
    const chosen = account.options.find((o) => o.period === period);
    return (
        <>
            <dl className="pay-facts">
                <div><dt>{l.customer}</dt><dd>{account.customer}</dd></div>
                <div><dt>{l.plan}</dt><dd>{account.service}</dd></div>
                {account.paidUntil && <div><dt>{l.paid_until}</dt><dd>{account.paidUntil}</dd></div>}
                <div><dt>{l.contract_until}</dt><dd>{account.contractUntil}</dd></div>
            </dl>
            {account.options.length === 0 ? <p className="pay-note" role="status">{l.no_period}</p> : (
                <form onSubmit={(e) => { e.preventDefault(); form.transform(() => ({ period, provider })); form.post('/pay/start'); }}>
                    <fieldset className="pay-periods">
                        <legend>{l.period}</legend>
                        {account.options.map((o) => (
                            <label key={o.period} className={o.period === period ? 'pay-period selected' : 'pay-period'}>
                                <input type="radio" name="period" value={o.period} checked={o.period === period} onChange={() => setPeriod(o.period)} />
                                <span>{o.months} {o.months === 1 ? l.month : l.months}</span>
                                <strong>{o.amount} {o.currency}</strong>
                            </label>
                        ))}
                    </fieldset>
                    {chosen && <p className="pay-muted">{l.covers}: {chosen.start} – {chosen.end}</p>}
                    {providers.length > 1 && (
                        <fieldset className="pay-periods">
                            <legend>{l.method}</legend>
                            {providers.map((p) => (
                                <label key={p.id} className={p.id === provider ? 'pay-period selected' : 'pay-period'}>
                                    <input type="radio" name="provider" value={p.id} checked={p.id === provider} onChange={() => setProvider(p.id)} />
                                    <span>{p.name}</span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <button type="submit" className="pay-button" disabled={form.processing || !chosen}>{l.pay}{chosen ? ` ${chosen.amount} ${chosen.currency}` : ''}</button>
                    <p className="pay-muted">{l.secure}</p>
                </form>
            )}
            <button type="button" className="pay-link" onClick={() => router.post('/pay/forget')}>{l.another}</button>
        </>
    );
}

/** The public pay page. It has its own plain shell: no operator menu, and the company's colour and logo. */
export default function Pay({ page, account, state }: { page: PageData; account: Account | null; state: State | null }) {
    const { flash, logo } = useShared();
    const form = useForm({ username: '', phone: '' });
    const l = page.labels;
    const theme = { '--pay-color': page.color, '--pay-color-text': page.colorText } as CSSProperties;
    return (
        <>
            <Head title={`${page.title} - ${page.company}`} />
            <main className="pay" style={theme} lang={page.language}>
                <div className="pay-card">
                    <header className="pay-head">
                        {logo ? <img src={logo} alt={page.company} className="pay-logo" /> : <strong className="pay-company">{page.company}</strong>}
                    </header>
                    <h1>{page.title}</h1>
                    {page.testing && <p className="pay-test" role="note">{l.test_mode}</p>}
                    {flash && <FlashMessage flash={flash} />}
                    {state ? <Result state={state} l={l} /> : account ? <Periods key={account.username} account={account} l={l} providers={page.providers} /> : (
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/pay/lookup'); }}>
                            <p className="pay-intro">{page.intro}</p>
                            <label className="pay-field">
                                {l.username}
                                <input name="username" required maxLength={64} autoComplete="username" autoCapitalize="none" spellCheck={false} value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} />
                            </label>
                            <label className="pay-field">
                                {l.phone}
                                <input name="phone" required inputMode="numeric" pattern="[0-9]{4}" minLength={4} maxLength={4} autoComplete="off" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value.replace(/\D/g, '').slice(0, 4))} />
                            </label>
                            <button type="submit" className="pay-button" disabled={form.processing}>{l.find}</button>
                        </form>
                    )}
                    {page.footer && <footer className="pay-footer">{page.footer}</footer>}
                </div>
            </main>
        </>
    );
}
