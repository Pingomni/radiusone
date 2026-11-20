import { Link } from '@inertiajs/react';
import Icon from './Icon';
import { DataTable } from './table';

export type InvoiceStatusData = { status: string; label: string; tone: string; payable: boolean; note: string; total: number; paid: number };
export type Receipt = { href: string; label: string } | null;
export type InvoiceRow = {
    id: number; number: string; username: string; period: string; issued: string; due: string; total: string; paid: string; balance: string;
    status: InvoiceStatusData; draftPdf: boolean; receipt: Receipt;
};
export type Stat = { label: string; value: string; note: string; tone: string };

/** The payment receipt of an invoice: Print opens it in a new tab, where the browser prints it; Save downloads it. */
export function ReceiptActions({ invoiceId, buttons = false }: { invoiceId: number; buttons?: boolean }) {
    const href = `/billing/invoices/${invoiceId}/payment-pdf`;
    const className = buttons ? 'button' : 'receipt-link';
    return (
        <span className="receipt-actions">
            <a className={className} href={`${href}?print=1`} target="_blank" rel="noopener">{buttons && <Icon name="printer" />}Print{buttons ? ' receipt' : ''}</a>
            <a className={className} href={href} download>{buttons && <Icon name="download" />}Save{buttons ? ' PDF' : ''}</a>
        </span>
    );
}

/** Paid amount against the total; drafts and cancelled invoices are not payable. */
export function PaymentProgress({ status }: { status: InvoiceStatusData }) {
    if (!status.payable) return <small className="pay-note">{status.note}</small>;
    const max = status.total > 0 ? status.total : 1;
    const value = status.total > 0 ? Math.min(status.paid, status.total) : 1;
    return (
        <>
            <progress className="pay-progress" max={max} value={value} aria-hidden="true" />
            <small className="pay-note">{status.note}</small>
        </>
    );
}

const TONES: Record<string, 'good' | 'warning' | 'danger' | 'neutral'> = { success: 'good', warning: 'warning', danger: 'danger' };

/** An invoice's state as a dot and a word; while part of it is owed, how much is paid. */
export function InvoiceStatus({ status }: { status: InvoiceStatusData }) {
    const owing = status.payable && status.paid > 0 && status.paid < status.total;
    return (
        <div className="invoice-status-cell" title={`Status: ${status.status}`}>
            <span className={`status is-${TONES[status.tone] ?? 'neutral'}`}>{status.label}</span>
            {owing && <small className="pay-note">{status.note}</small>}
        </div>
    );
}

export function StatCards({ stats, className = 'client-stats' }: { stats: Stat[]; className?: string }) {
    return (
        <div className={className}>
            {stats.map((s) => (
                <div key={s.label} className={`client-stat${s.tone ? ` tone-${s.tone}` : ''}`}>
                    <span>{s.label}</span><strong>{s.value}</strong>{s.note && <small>{s.note}</small>}
                </div>
            ))}
        </div>
    );
}

export function InvoiceDocuments({ invoice }: { invoice: Pick<InvoiceRow, 'id' | 'draftPdf' | 'receipt'> }) {
    return (
        <>
            {invoice.draftPdf && <><a href={`/billing/invoices/${invoice.id}/draft-pdf`}>Payment request</a>{' '}</>}
            {invoice.receipt && <span className="receipt-line" title={invoice.receipt.label}>Receipt: <ReceiptActions invoiceId={invoice.id} /></span>}
        </>
    );
}

/** Invoices of one customer (client Billing tab). */
export function InvoiceTable({ invoices }: { invoices: InvoiceRow[] }) {
    return (
        <DataTable
            rows={invoices}
            rowKey={(i) => i.id}
            exportRows
            columns={[
                { header: 'Invoice', cell: (i) => <><Link href={`/billing/invoices/${i.id}`}>{i.number}</Link><span className="list-subline">Issued {i.issued} · {i.period}</span></> },
                { header: 'Due', cell: (i) => i.due },
                { header: 'Total', cell: (i) => i.total },
                { header: 'Balance due', cell: (i) => i.balance },
                { header: 'Status', cell: (i) => <InvoiceStatus status={i.status} /> },
                { header: 'Documents', noExport: true, cell: (i) => <div className="list-cell-stack"><InvoiceDocuments invoice={i} /></div> },
            ]}
        />
    );
}
