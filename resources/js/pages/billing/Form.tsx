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
    const customer = customers.find((c) => c.value === data.username);

    // Mirrors BillingRepository::saveInvoice: (plan + static IP) x months - discount + tax.
    const months = MONTHS[data.billing_period] ?? 1;
    const amount = (value: string) => Math.max(0, Number(value) || 0);
    const currency = data.currency.toUpperCase();
    const format = (value: number) => `${value.toFixed(2)} ${currency}`.trim();
    const suffix = months > 1 ? ` × ${months}` : '';
    const serviceTotal = (service?.price ?? 0) * months;
    const staticTotal = (pool?.price ?? 0) * months;
    const discount = amount(data.discount_amount);
    const tax = amount(data.tax_amount);
    const total = Math.max(0, serviceTotal + staticTotal - discount + tax);
    const due = dueDate(data.issue_date, data.billing_period);

    // The two buttons choose the status: issue now (unpaid) or keep as a draft.
    const save = (status: string) => {
        form.transform((d) => ({ ...d, status }));
        form.post('/billing/invoices/save', { preserveScroll: true });
    };

    return (
        <AppLayout title={values.id ? 'Edit draft invoice' : 'New invoice'}>
            <div className="toolbar"><Link href="/billing">← Billing</Link></div>
            <form id="invoice-form" className="invoice-form-layout" onSubmit={(e) => { e.preventDefault(); save('open'); }}>
                <Panel>
                    <ErrorSummary errors={form.errors} />
                    <div className="form-grid">
                        <h3 className="form-section-title span2">Who and what</h3>
                        {/* Type to search: a plain select of every customer does not scale to thousands. */}
                        <Field label="Customer" required list="invoice-customers" autoComplete="off" placeholder="Type a name or username" hint={customer ? customer.label : 'Pick from the suggestions.'} {...bind(form, 'username')} />
                        <datalist id="invoice-customers">
                            {customers.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </datalist>
                        <Select
                            label="Service plan"
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
                        {staticPools.length > 0 && (
                            <Select
                                label="Static IP add-on"
                                placeholder="None"
                                options={staticPools}
                                {...bind(form, 'static_ip_pool_key')}
                                onChange={(e) => {
                                    const next = staticPools.find((p) => p.value === e.target.value);
                                    form.setData((d) => ({ ...d, static_ip_pool_key: e.target.value, currency: !d.service_group && next?.currency ? next.currency : d.currency }));
                                }}
                            />
                        )}
                        <Select label="Billing period" options={periods} {...bind(form, 'billing_period')} />
                        <Field label="Issue date" type="date" required hint={due ? `Due ${due}` : undefined} {...bind(form, 'issue_date')} />

                        <details className="span2" open={discount > 0 || tax > 0 || data.notes !== '' || undefined}>
                            <summary>Discount, tax, description and notes</summary>
                            <div className="form-grid">
                                <Field label={`Discount (${currency})`} type="number" step="0.01" min="0" {...bind(form, 'discount_amount')} />
                                <Field label={`Tax (${currency})`} type="number" step="0.01" min="0" {...bind(form, 'tax_amount')} />
                                <Field label="Line description" required hint="Printed on the PDF." {...bind(form, 'description')} />
                                <TextArea label="Notes" {...bind(form, 'notes')} />
                            </div>
                        </details>
                    </div>
                </Panel>

                <aside className="panel invoice-preview" aria-live="polite">
                    <h2>Summary</h2>
                    <div className="invoice-totals">
                        <div className="kv"><span>{service ? service.name : 'Internet'}{service && suffix}</span><strong>{serviceTotal ? format(serviceTotal) : '—'}</strong></div>
                        {pool && <div className="kv"><span>Static IP{suffix}</span><strong>{format(staticTotal)}</strong></div>}
                        {discount > 0 && <div className="kv"><span>Discount</span><strong>− {format(discount)}</strong></div>}
                        {tax > 0 && <div className="kv"><span>Tax</span><strong>+ {format(tax)}</strong></div>}
                        <div className="kv invoice-total"><span>Total</span><strong>{format(total)}</strong></div>
                    </div>
                    {due && <p className="hint">Due {due}. The saved invoice is the final amount.</p>}
                    <div className="invoice-preview-actions">
                        <button type="submit" className="primary" disabled={form.processing}>Issue invoice</button>
                        <button type="button" disabled={form.processing} onClick={() => save('draft')}>Save as draft</button>
                    </div>
                </aside>
            </form>
        </AppLayout>
    );
}
