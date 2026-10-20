import { useForm } from '@inertiajs/react';
import { Sheet } from '@/components/settings';
import { TemplateField } from '@/settings/fields';
import type { Documents } from '@/settings/types';

const DOCUMENT_HINTS: Record<string, string> = {
    company_name: 'At the top of every PDF.',
    company_details: 'Address, phone, email, tax number (NIPT). Up to 4 lines, shown in the header.',
    draft_note: 'Bank account, IBAN, where and how to pay. Shown beside the totals.',
    receipt_note: 'Shown beside the totals.',
};

export function DocumentsTab({ d }: { d: Documents }) {
    const form = useForm({ ...d.values });
    const field = (key: string) => (
        <TemplateField
            key={key}
            label={d.labels[key]}
            name={key}
            value={form.data[key] ?? ''}
            fallback={d.defaults[key]}
            multiline={d.lines[key] > 1}
            maxRows={d.lines[key] + 1}
            hint={DOCUMENT_HINTS[key]}
            error={form.errors[key]}
            onChange={(v) => form.setData(key, v)}
        />
    );
    const preview = (kind: 'request' | 'receipt', label: string) => (
        <a className="button" href={`/settings/documents/preview/${kind}`} target="_blank" rel="noreferrer" aria-disabled={form.isDirty || undefined} onClick={(e) => form.isDirty && e.preventDefault()} title={form.isDirty ? 'Save first' : undefined}>{label}</a>
    );
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/documents', { preserveScroll: true }); }}>
            <Sheet title="Invoices & receipts" description="The wording of the payment request (draft PDF) and payment receipt your customers get. Edit the text in use; an empty field goes back to the default." icon="billing">
                <div className="form-grid template-fields">
                    {field('company_name')}
                    {field('company_details')}
                    <p className="span2 hint">Titles, notes and footers can use: {d.placeholders.map((p) => `{${p}}`).join(' ')}</p>
                </div>
            </Sheet>
            <Sheet title="Payment request" description="The PDF sent before payment (Billing → invoice → Draft PDF)." icon="billing">
                <div className="form-grid template-fields">
                    {field('draft_title')}
                    {field('draft_note')}
                    {field('draft_footer')}
                    <div className="span2 form-actions">{preview('request', 'Preview payment request')}</div>
                </div>
            </Sheet>
            <Sheet title="Payment receipt" description="The PDF that confirms a payment (Billing → invoice → Payment receipt)." icon="billing">
                <div className="form-grid template-fields">
                    {field('receipt_title')}
                    {field('receipt_partial_title')}
                    {field('receipt_note')}
                    {field('receipt_footer')}
                    <div className="span2 form-actions">{preview('receipt', 'Preview receipt')}</div>
                </div>
            </Sheet>
            <div className="form-actions"><button className="primary" disabled={form.processing}>Save invoices & receipts</button></div>
        </form>
    );
}
