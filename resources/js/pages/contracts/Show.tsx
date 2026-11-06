import { Link } from '@inertiajs/react';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { SettingsRows } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Panel } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { statusTone, type ContractRow } from './Index';

export type PayRow = { id: number; provider?: string; status: string; amount: string; currency: string; period_end: string; created_at: string; note: string; invoice_id: number | null };

export function PayRows({ rows, username }: { rows: (PayRow & { username?: string })[]; username?: boolean }) {
    return (
        <DataTable<PayRow & { username?: string }>
            rows={rows}
            rowKey={(r) => r.id}
            emptyText="No online payments yet."
            columns={[
                { header: 'Started', cell: (r) => <Link href={`/payments/${r.id}`}>{r.created_at}</Link> },
                ...(username ? [{ header: 'Customer', cell: (r: PayRow & { username?: string }) => r.username }] : []),
                { header: 'Amount', cell: (r) => `${r.amount} ${r.currency}${r.provider ? `, ${r.provider}` : ''}` },
                { header: 'Covers until', cell: (r) => r.period_end },
                { header: 'Status', cell: (r) => <><Badge tone={r.status === 'paid' ? 'success' : r.status === 'mismatch' || r.status === 'failed' ? 'danger' : ''}>{r.status === 'mismatch' ? 'needs review' : r.status}</Badge>{r.note && <><br /><small className="muted">{r.note}</small></>}</> },
                { header: 'Invoice', cell: (r) => (r.invoice_id ? <Link href={`/billing/invoices/${r.invoice_id}`}>Open</Link> : '') },
            ]}
        />
    );
}

export default function Show({ contract, payments, canManage }: {
    contract: ContractRow & { body: string; firstInvoiceId: number | null };
    payments: PayRow[];
    canManage: boolean;
}) {
    return (
        <AppLayout title={contract.number}>
            <Panel
                title={contract.number}
                actions={<>
                    <a className="button" href={`/contracts/${contract.id}/pdf`}><Icon name="reports" />Download PDF</a>
                    {canManage && contract.status === 'active' && (
                        <PostButton action={`/contracts/${contract.id}/cancel`} label="Cancel contract" className="button danger" confirm={{ title: 'Cancel this contract?', message: 'The customer can no longer pay on the pay page. Invoices already issued are not changed.', confirmLabel: 'Cancel contract' }} />
                    )}
                </>}
            >
                <SettingsRows rows={[
                    ['Status', <Badge tone={statusTone(contract.status)}>{contract.status}</Badge>],
                    ['Customer', <Link href={`/clients/${encodeURIComponent(contract.username)}/edit`}>{contract.customer || contract.username}</Link>],
                    ['Plan', `${contract.service}, ${contract.monthlyPrice} ${contract.currency} a month`],
                    ['Billed', contract.period],
                    ['Term', `${contract.start} to ${contract.end} (${contract.term} months)`],
                    ['First invoice', contract.firstInvoiceId ? <Link href={`/billing/invoices/${contract.firstInvoiceId}`}>Open</Link> : 'None'],
                ]} />
            </Panel>
            <Panel title="Contract text">
                <div className="contract-body">
                    {contract.body.split('\n').map((line, i) => (line.startsWith('# ') ? <h3 key={i}>{line.slice(2)}</h3> : <p key={i}>{line || '\u00a0'}</p>))}
                </div>
            </Panel>
            <Panel title="Online payments"><PayRows rows={payments} /></Panel>
        </AppLayout>
    );
}
