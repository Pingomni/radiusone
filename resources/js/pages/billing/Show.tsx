import { Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { PaymentProgress, type InvoiceStatusData, type Receipt } from '@/components/billing';
import { PostButton } from '@/components/confirm';
import { ErrorSummary, Field, Panel, RowActions, Select, TextArea } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { bind } from '@/lib/form';

type Invoice = {
    id: number; number: string; username: string; notes: string; status: InvoiceStatusData;
    banner: { tone: string; title: string; text: string };
    customer: string; service: string; issued: string; due: string; period: string;
    totals: [string, string, string][]; draftPdf: boolean; receipt: Receipt;
};
type Item = { description: string; total: string };
type Payment = { id: number; date: string; amount: string; method: string; reference: string; receiver: string; notes: string };
type PaymentValues = { payment_date: string; amount: string; method: string; reference: string; notes: string };
type PaymentState =
    | { recorded: { id: number; amount: string }; key: string }
    | { recorded: null; key: string; values: PaymentValues };

function RecordPayment({ invoiceId, requestKey, values, methods }: { invoiceId: number; requestKey: string; values: PaymentValues; methods: Record<string, string> }) {
    const form = useForm({ ...values, request_key: requestKey });
    return (
        <Panel title="Record payment">
            <ErrorSummary errors={form.errors} />
            <form className="form-grid invoice-pay-form" onSubmit={(e) => { e.preventDefault(); form.post(`/billing/invoices/${invoiceId}/payment`, { preserveScroll: true }); }}>
                <Field label="Amount" type="number" step="0.01" min="0.01" required {...bind(form, 'amount')} />
                <Field label="Date" type="date" required {...bind(form, 'payment_date')} />
                <Select label="Method" options={methods} {...bind(form, 'method')} />
                <Field label="Reference (optional)" placeholder="Bank or receipt number" {...bind(form, 'reference')} />
                <details className="span2">
                    <summary>Add a note</summary>
                    <TextArea label="Note" {...bind(form, 'notes')} />
                </details>
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
    const client = `/clients/${encodeURIComponent(invoice.username)}/edit`;

    return (
        <AppLayout title={`Invoice ${invoice.number}`}>
            <div className="toolbar invoice-toolbar">
                <Link href="/billing">← Billing</Link>
                <span className="toolbar-actions">
                    {invoice.draftPdf && <a className="button" href={`/billing/invoices/${invoice.id}/draft-pdf`}>Payment request PDF</a>}
                    {receiptButton}
                    {canEdit && <Link className="button primary" href={`/billing/invoices/${invoice.id}/edit`}>Edit draft</Link>}
                    {(canVoid || canDelete) && (
                        <RowActions label={`More actions for ${invoice.number}`}>
                            {canVoid && <PostButton action={`/billing/invoices/${invoice.id}/void`} label="Cancel invoice" confirm={{ title: `Cancel ${invoice.number}?`, message: 'Cancel this invoice? It will be marked void.', confirmLabel: 'Cancel invoice' }} />}
                            {canDelete && <PostButton action={`/billing/invoices/${invoice.id}/delete`} label="Delete draft" confirm={{ title: `Delete ${invoice.number}?`, message: 'Delete this unpaid draft permanently?', confirmLabel: 'Delete draft' }} />}
                        </RowActions>
                    )}
                </span>
            </div>
            <section className={`invoice-status-banner tone-${banner.tone}`} role="status">
                <div><strong>{banner.title}</strong>{banner.text && <p>{banner.text}</p>}</div>
                {invoice.status.payable && <div className="invoice-status-progress"><PaymentProgress status={invoice.status} /></div>}
            </section>
            <div className="invoice-layout">
                <Panel className="invoice-sheet">
                    <div className="invoice-parties">
                        <div>
                            <span className="invoice-label">Bill to</span>
                            <Link href={client}><strong>{invoice.customer}</strong></Link>
                            <span className="list-subline">{invoice.username}{invoice.service && ` · ${invoice.service}`}</span>
                        </div>
                        <dl className="invoice-dates">
                            <div><dt>Issued</dt><dd>{invoice.issued}</dd></div>
                            <div><dt>Due</dt><dd>{invoice.due}</dd></div>
                            <div><dt>Period</dt><dd>{invoice.period}</dd></div>
                        </dl>
                    </div>
                    <ul className="invoice-lines">
                        {items.map((i, n) => <li key={n}><span>{i.description}</span><strong>{i.total}</strong></li>)}
                    </ul>
                    <div className="invoice-totals">
                        {invoice.totals.map(([label, value, kind]) => <div key={label} className={`kv ${kind ? `invoice-${kind}` : ''}`.trim()}><span>{label}</span><strong>{value}</strong></div>)}
                    </div>
                    {invoice.notes && <p className="hint invoice-notes">{invoice.notes}</p>}
                </Panel>
                <div className="stack-panels">
                    {payment && (payment.recorded ? (
                        <Panel title="Payment recorded">
                            <p className="invoice-recorded">{payment.recorded.amount} is recorded.</p>
                            <div className="form-actions">
                                {receiptButton}
                                <button type="button" onClick={() => router.post(`/billing/invoices/${invoice.id}/payment/new`, { previous_request_key: payment.key }, { preserveScroll: true })}>Record another payment</button>
                            </div>
                        </Panel>
                    ) : (
                        <div id="record-payment"><RecordPayment key={payment.key} invoiceId={invoice.id} requestKey={payment.key} values={payment.values} methods={methods} /></div>
                    ))}
                    {(payments.length > 0 || invoice.status.payable) && (
                        <Panel title="Payments">
                            {payments.length === 0 ? <p className="list-empty">No payments yet.</p> : (
                                <ul className="billing-payments">
                                    {payments.map((p) => (
                                        <li key={p.id}>
                                            <div>
                                                {p.date} · {p.method}{p.reference && ` · ${p.reference}`}
                                                <span className="list-subline">by {p.receiver}{p.notes && ` · ${p.notes}`}</span>
                                            </div>
                                            <strong>{p.amount}</strong>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    )}
                </div>
            </div>
            {classify && <Classify invoiceId={invoice.id} options={classify} />}
        </AppLayout>
    );
}
