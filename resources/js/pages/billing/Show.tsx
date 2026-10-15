import { Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { InvoiceStatus, PaymentProgress, type InvoiceStatusData, type Receipt } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import { DataTable } from '@/components/table';
import { ErrorSummary, Field, Panel, Select, TextArea } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { bind } from '@/lib/form';

type Invoice = {
    id: number; number: string; username: string; notes: string; status: InvoiceStatusData;
    banner: { tone: string; title: string; text: string }; summary: [string, string][]; draftPdf: boolean; receipt: Receipt;
};
type Item = { description: string; period: string; total: string };
type Payment = { id: number; date: string; amount: string; method: string; reference: string; receiver: string; notes: string };
type PaymentValues = { payment_date: string; amount: string; method: string; reference: string; notes: string };
type PaymentState =
    | { recorded: { id: number; amount: string }; key: string }
    | { recorded: null; key: string; values: PaymentValues };

function RecordPayment({ invoiceId, requestKey, values, methods }: { invoiceId: number; requestKey: string; values: PaymentValues; methods: Record<string, string> }) {
    const form = useForm({ ...values, request_key: requestKey });
    return (
        <Panel title="Record Payment">
            <ErrorSummary errors={form.errors} />
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post(`/billing/invoices/${invoiceId}/payment`, { preserveScroll: true }); }}>
                <Field label="Payment date" type="date" required {...bind(form, 'payment_date')} />
                <Field label="Amount" type="number" step="0.01" min="0.01" required {...bind(form, 'amount')} />
                <Select label="Method" options={methods} {...bind(form, 'method')} />
                <Field label="Reference" {...bind(form, 'reference')} />
                <TextArea label="Notes" {...bind(form, 'notes')} />
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Record payment</button></div>
            </form>
        </Panel>
    );
}

function Classify({ invoiceId, options }: { invoiceId: number; options: Record<string, string> }) {
    const form = useForm({ classification: Object.keys(options)[0] ?? '', anchor_day: '', evidence: '' });
    return (
        <Panel title="Legacy invoice review">
            <p>Classify only from reviewed evidence. Leave the original anchor blank when unknown.</p>
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post(`/billing/invoices/${invoiceId}/classify`, { preserveScroll: true }); }}>
                <Select label="Classification" options={options} {...bind(form, 'classification')} />
                <Field label="Original sold anchor (optional)" type="number" min="1" max="31" {...bind(form, 'anchor_day')} />
                <TextArea label="Review evidence" {...bind(form, 'evidence')} />
                <button disabled={form.processing}>Record classification</button>
            </form>
        </Panel>
    );
}

export default function InvoiceView({ invoice, items, payments, payment, methods, canEdit, canVoid, canDelete, classify, download }: {
    invoice: Invoice;
    items: Item[];
    payments: Payment[];
    payment: PaymentState | null;
    methods: Record<string, string>;
    canEdit: boolean;
    canVoid: boolean;
    canDelete: boolean;
    classify: Record<string, string> | null;
    download: string | null;
}) {
    // After a payment is recorded the receipt downloads, as the legacy page did (attachment: the page stays).
    useEffect(() => {
        if (download) window.location.assign(download);
    }, [download]);

    const receiptButton = invoice.receipt && <a className="button" href={invoice.receipt.href}>{invoice.receipt.label}</a>;
    const banner = invoice.banner;
    const payable = invoice.status.payable;

    return (
        <AppLayout title={`Invoice ${invoice.number}`}>
            <div className="toolbar">
                <span className="toolbar-actions">
                    <Link className="button" href="/billing">Back to billing</Link>
                    <Link className="button" href={`/clients/${encodeURIComponent(invoice.username)}/edit`}>Customer profile</Link>
                    {invoice.draftPdf && <a className="button" href={`/billing/invoices/${invoice.id}/draft-pdf`}>Payment request PDF</a>}
                    {receiptButton}
                    {canEdit && <Link className="button" href={`/billing/invoices/${invoice.id}/edit`}>Edit invoice</Link>}
                    {canVoid && <PostButton action={`/billing/invoices/${invoice.id}/void`} label="Cancel invoice" className="danger" confirm={{ title: `Cancel ${invoice.number}?`, message: 'Cancel this invoice? It will be marked void.', confirmLabel: 'Cancel invoice' }} />}
                    {canDelete && <PostButton action={`/billing/invoices/${invoice.id}/delete`} label="Delete draft" className="danger" confirm={{ title: `Delete ${invoice.number}?`, message: 'Delete this unpaid draft permanently?', confirmLabel: 'Delete draft' }} />}
                </span>
            </div>
            {classify && <Classify invoiceId={invoice.id} options={classify} />}
            <section className={`invoice-status-banner tone-${banner.tone}`} role="status">
                <div><strong>{banner.title}</strong>{banner.text && <p>{banner.text}</p>}</div>
                {payable && <div className="invoice-status-progress"><PaymentProgress status={invoice.status} /></div>}
            </section>
            <div className="grid2">
                <Panel title="Invoice Summary">
                    <section className="invoice-summary">
                        {invoice.summary.slice(0, 2).map(([label, value]) => <div key={label} className="kv"><span>{label}</span><strong>{value}</strong></div>)}
                        <div className="kv"><span>Status</span><InvoiceStatus status={invoice.status} /></div>
                        {invoice.summary.slice(2).map(([label, value]) => <div key={label} className="kv"><span>{label}</span><strong>{value}</strong></div>)}
                    </section>
                    {invoice.notes && <p className="hint">{invoice.notes}</p>}
                </Panel>
                <Panel title="Line Items">
                    <DataTable rows={items} columns={[
                        { header: 'Description', cell: (i) => i.description },
                        { header: 'Billing period', cell: (i) => i.period },
                        { header: 'Line total', cell: (i) => i.total },
                    ]} />
                </Panel>
            </div>
            {payment && (payment.recorded ? (
                <Panel title="Payment recorded">
                    <p>Payment {payment.recorded.id} of {payment.recorded.amount} is recorded.</p>
                    {receiptButton}{' '}
                    <button type="button" onClick={() => router.post(`/billing/invoices/${invoice.id}/payment/new`, { previous_request_key: payment.key }, { preserveScroll: true })}>Start another payment</button>
                </Panel>
            ) : (
                <RecordPayment key={payment.key} invoiceId={invoice.id} requestKey={payment.key} values={payment.values} methods={methods} />
            ))}
            <Panel title="Payments">
                <DataTable rows={payments} rowKey={(p) => p.id} exportRows columns={[
                    { header: 'Date', cell: (p) => p.date },
                    { header: 'Amount', cell: (p) => p.amount },
                    { header: 'Method', cell: (p) => p.method },
                    { header: 'Reference', cell: (p) => p.reference },
                    { header: 'Received by', cell: (p) => p.receiver },
                    { header: 'Notes', cell: (p) => p.notes },
                ]} />
            </Panel>
        </AppLayout>
    );
}
