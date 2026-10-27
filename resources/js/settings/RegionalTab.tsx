import { useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm';
import { Sheet } from '@/components/settings';
import { Badge, Select } from '@/components/ui';
import type { Regional } from '@/settings/types';

export function RegionalTab({ r }: { r: Regional }) {
    const zone = useForm({ timezone: r.timezone });
    const money = useForm({ currency: r.currency });
    const ask = useConfirm();
    const last = r.timezoneLog[r.timezoneLog.length - 1] ?? '';
    const running = /(scheduled|started): /.test(last);

    return (
        <>
            <Sheet
                title="Time zone"
                description="The server's clock and time zone: RADIUS, accounting, invoices, reports and scheduled jobs all use it."
                icon="calendar"
                meta={<Badge tone={running ? 'warning' : ''}>{running ? 'change in progress' : r.timezone}</Badge>}
            >
                <form
                    className="form-grid"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        const ok = await ask({
                            title: `Switch to ${zone.data.timezone}?`,
                            message: 'A database backup is taken, then the portal pauses for about a minute while every stored time is converted to the new zone. '
                                + 'RADIUS stops for a few seconds while session records are converted. Customer expiry, invoices and scheduled jobs follow the new zone afterwards.',
                            confirmLabel: 'Switch time zone',
                        });
                        if (ok) zone.post('/settings/timezone', { preserveScroll: true });
                    }}
                >
                    <Select label="Time zone" options={r.timezones} name="timezone" value={zone.data.timezone} error={zone.errors.timezone} onChange={(e) => zone.setData('timezone', e.target.value)} />
                    <div className="span2 form-actions">
                        <button className="primary" disabled={zone.processing || running || !r.timezoneReady || zone.data.timezone === r.timezone}>Switch time zone</button>
                    </div>
                    {!r.timezoneReady && <p className="span2 hint">To change it from here, run <code>sudo bash ops/radiusone-postinstall</code> on the server once.</p>}
                    {r.timezoneLog.length > 0 && (
                        <details className="span2" open={running}>
                            <summary>Last change</summary>
                            <pre className="mono">{r.timezoneLog.join('\n')}</pre>
                        </details>
                    )}
                </form>
            </Sheet>
            <Sheet title="Currency" description="The currency the whole system bills in: service plans, static IP pools, invoices and payments." icon="billing" meta={<Badge>{r.currency}</Badge>}>
                <form
                    className="form-grid"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        const ok = await ask({
                            title: `Bill in ${r.currencies[money.data.currency]}?`,
                            message: 'Service plan and IP pool prices and draft invoices switch to the new currency. Amounts are not converted: check your prices afterwards. '
                                + 'Invoices already issued, and their payments, keep their currency.',
                            confirmLabel: 'Change currency',
                        });
                        if (ok) money.post('/settings/currency', { preserveScroll: true });
                    }}
                >
                    <Select label="Currency" options={r.currencies} name="currency" value={money.data.currency} error={money.errors.currency} onChange={(e) => money.setData('currency', e.target.value)} />
                    <div className="span2 form-actions">
                        <button className="primary" disabled={money.processing || money.data.currency === r.currency}>Change currency</button>
                    </div>
                    {r.issuedOther > 0 && <p className="span2 hint">{r.issuedOther} issued invoice{r.issuedOther === 1 ? ' is' : 's are'} in an earlier currency; totals show each currency separately.</p>}
                </form>
            </Sheet>
        </>
    );
}
