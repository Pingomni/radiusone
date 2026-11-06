import { Link, useForm } from '@inertiajs/react';
import { Check, ErrorSummary, Field, FormActions, Panel, Select } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';

type Values = { username: string; term_months: string; billing_period: string; start_date: string; issue_invoice: boolean };

export default function Form({ values, periods }: { values: Values; periods: Record<string, string> }) {
    const form = useForm<Values>(values);
    const errors = form.errors as Record<string, string | undefined>;
    return (
        <AppLayout title="New contract">
            <Panel title="New contract">
                <ErrorSummary errors={errors} />
                <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/contracts/save'); }}>
                    <Field label="Customer username" name="username" required value={form.data.username} error={errors.username} hint="The contract uses this customer's details and current plan." onChange={(e) => form.setData('username', e.target.value)} />
                    <Field label="Start date" name="start_date" type="date" required value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                    <Field label="Term (months)" name="term_months" type="number" min={1} max={120} required value={form.data.term_months} error={errors.term_months} hint="The customer cannot pay online for time past the end of the term." onChange={(e) => form.setData('term_months', e.target.value)} />
                    <Select label="Billing period" name="billing_period" options={periods} value={form.data.billing_period} hint="How often the customer is invoiced." onChange={(e) => form.setData('billing_period', e.target.value)} />
                    <Check className="check span2" label="Issue the first invoice for this period now" checked={form.data.issue_invoice} onChange={(e) => form.setData('issue_invoice', e.target.checked)} />
                    <FormActions>
                        <button className="button primary" type="submit" disabled={form.processing}>Issue contract</button>
                        <Link className="button" href="/contracts">Cancel</Link>
                    </FormActions>
                </form>
            </Panel>
        </AppLayout>
    );
}
