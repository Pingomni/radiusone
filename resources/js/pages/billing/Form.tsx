import { Link, useForm } from '@inertiajs/react';
import { ErrorSummary, Field, Panel, Select, TextArea } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { bind } from '@/lib/form';
import type { Option } from '@/lib/types';

type Values = {
    id: number | ''; username: string; service_group: string; static_ip_pool_key: string; billing_period: string; description: string;
    currency: string; discount_amount: string; tax_amount: string; issue_date: string; status: string; notes: string;
};
type PricedOption = Option & { name: string; price: number; currency: string };

const MONTHS: Record<string, number> = { monthly: 1, '2_months': 2, '3_months': 3, '6_months': 6, yearly: 12 };

/** Mirrors BillingCalendar::end: the day before the same day `months` later (clamped to month end). */
function dueDate(issue: string, period: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(issue);
    if (!match) return '';
    const [year, month, day] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(year, month - 1 + (MONTHS[period] ?? 1), 1));
    const lastDay = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
    date.setUTCDate(Math.min(day, lastDay) - 1);
    return date.toISOString().slice(0, 10);
}

export default function InvoiceForm({ values, customers, services, staticPools, periods }: {
    values: Values;
    customers: Option[];
    services: PricedOption[];
    staticPools: PricedOption[];
    periods: Record<string, string>;
}) {
    const form = useForm<Values>(values);
    const { data } = form;
    const service = services.find((s) => s.value === data.service_group);
    const pool = staticPools.find((p) => p.value === data.static_ip_pool_key);

    // Mirrors BillingRepository::saveInvoice: (plan + static IP) x months - discount + tax.
    const months = MONTHS[data.billing_period] ?? 1;
    const amount = (value: string) => Math.max(0, Number(value) || 0);
    const currency = data.currency.toUpperCase();
    const format = (value: number) => `${value.toFixed(2)} ${currency}`.trim();
    const suffix = months > 1 ? ` (${months} months)` : '';
    const serviceTotal = (service?.price ?? 0) * months;
    const staticTotal = (pool?.price ?? 0) * months;
    const discount = amount(data.discount_amount);
    const tax = amount(data.tax_amount);

    return (
        <AppLayout title={values.id ? 'Edit Invoice' : 'New Invoice'}>
            <Panel title="Invoice">
                <ErrorSummary errors={form.errors} />
                <form
                    id="invoice-form"
                    className="form-grid"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/billing/invoices/save', { preserveScroll: true });
                    }}
                >
                    {/* Type to search: a plain select of every customer does not scale to thousands. */}
                    <Field label="Customer" required list="invoice-customers" autoComplete="off" placeholder="Type a name or username" hint="Choose from the suggestions; the username is saved." {...bind(form, 'username')} />
                    <datalist id="invoice-customers">
                        {customers.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                    </datalist>
                    <Select
                        label="Service plan"
                        hint="Choose a service for internet billing, or no internet charge for static-IP-only invoices."
                        placeholder="No internet charge"
                        options={services}
                        {...bind(form, 'service_group')}
                        onChange={(e) => {
                            const next = services.find((s) => s.value === e.target.value);
                            form.setData((d) => ({
                                ...d,
                                service_group: e.target.value,
                                currency: next?.currency || (!e.target.value && pool?.currency) || d.currency,
                                description: next ? `${next.name} service` : d.description,
                            }));
                        }}
                    />
                    <Select
                        label="Static IP add-on"
                        hint="Use with internet for new orders, or without internet for static-IP-only billing."
                        placeholder="No static IP charge"
                        options={staticPools}
                        {...bind(form, 'static_ip_pool_key')}
                        onChange={(e) => {
                            const next = staticPools.find((p) => p.value === e.target.value);
                            form.setData((d) => ({ ...d, static_ip_pool_key: e.target.value, currency: !d.service_group && next?.currency ? next.currency : d.currency }));
                        }}
                    />
                    <Select label="Billing period" options={periods} {...bind(form, 'billing_period')} />
                    <Field label="Description" required {...bind(form, 'description')} />
                    <Field label="Currency" readOnly name="currency" value={currency} hint="From the plan, or the system currency (Settings → Regional)." />
                    <Field label="Discount" type="number" step="0.01" min="0" {...bind(form, 'discount_amount')} />
                    <Field label="Tax" type="number" step="0.01" min="0" {...bind(form, 'tax_amount')} />
                    <Field label="Issue date" type="date" required {...bind(form, 'issue_date')} />
                    <Field label="Due date" type="date" required readOnly name="due_date" value={dueDate(data.issue_date, data.billing_period)} hint="Follows the issue date and billing period." />
                    <Select
                        label="Status"
                        hint="Payments are recorded on the invoice page after it is issued."
                        options={{ open: 'Issue now (unpaid)', draft: 'Draft (not billed yet)' }}
                        {...bind(form, 'status')}
                    />
                    <section className="span2 invoice-total-preview" aria-live="polite">
                        <h3>Price summary</h3>
                        <div className="kv"><span>Internet service</span><strong>{serviceTotal ? format(serviceTotal) + suffix : 'None'}</strong></div>
                        <div className="kv"><span>Static IP add-on</span><strong>{staticTotal ? format(staticTotal) + suffix : 'None'}</strong></div>
                        <div className="kv"><span>Discount</span><strong>{discount ? `− ${format(discount)}` : 'None'}</strong></div>
                        <div className="kv"><span>Tax</span><strong>{tax ? `+ ${format(tax)}` : 'None'}</strong></div>
                        <div className="kv invoice-total-line"><span>Total to bill</span><strong>{format(Math.max(0, serviceTotal + staticTotal - discount + tax))}</strong></div>
                        <small className="hint">Estimate from the selected plan prices; the saved invoice is the final amount.</small>
                    </section>
                    <TextArea label="Notes" {...bind(form, 'notes')} />
                    <div className="span2 form-actions">
                        <button className="primary" disabled={form.processing}>Save invoice</button>
                        <Link className="button" href="/billing">Cancel</Link>
                    </div>
                </form>
            </Panel>
        </AppLayout>
    );
}
