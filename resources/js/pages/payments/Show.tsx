import { Link, useForm } from '@inertiajs/react';
import { PostButton } from '@/components/confirm';
import { SettingsRows } from '@/components/settings';
import { Panel, TextArea } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { PaymentStatus, type PaymentRow } from './Index';

type Payment = PaymentRow & { order: string; contractId: number; contract: string; expiresAt: string; reviewedAt: string; reviewedBy: string; reviewNote: string };

export default function Show({ payment: p, canManage }: { payment: Payment; canManage: boolean }) {
    const form = useForm({ note: '' });
    return (
        <AppLayout title={`Online payment ${p.id}`}>
            <Panel
                title={`${p.amount} ${p.currency}`}
                actions={canManage && p.status === 'pending' ? <PostButton action={`/payments/${p.id}/check`} label="Check now" className="button" /> : undefined}
            >
                <SettingsRows rows={[
                    ['Status', <PaymentStatus status={p.status} />],
                    ['Customer', <Link href={`/clients/${encodeURIComponent(p.username)}/edit`}>{p.customer || p.username}</Link>],
                    ['Username', p.username],
                    ['Contract', p.contract ? <Link href={`/contracts/${p.contractId}`}>{p.contract}</Link> : ''],
                    ['Covers', `${p.periodStart} – ${p.periodEnd}`],
                    ['Service', p.provider],
                    ['Order at the service', p.order ? <code>{p.order}</code> : 'None'],
                    ['Started', p.createdAt],
                    ['Paid', p.paidAt || 'Not paid'],
                    ['Page expires', p.expiresAt],
                    ['Invoice', p.invoiceId ? <Link href={`/billing/invoices/${p.invoiceId}`}>{p.invoice}</Link> : 'None'],
                    ...(p.note ? [['Note', p.note] as [string, string]] : []),
                    ...(p.reviewedAt ? [['Reviewed', `${p.reviewedAt}, ${p.reviewedBy}: ${p.reviewNote}`] as [string, string]] : []),
                ]} />
            </Panel>
            {p.status === 'mismatch' && (
                <Panel title="This payment needs review">
                    <p className="hint">The customer may have paid, but it was not applied by itself. Check the order at {p.provider}, then record the payment under Billing or refund it at the service.</p>
                    {canManage && (
                        <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post(`/payments/${p.id}/review`); }}>
                            <TextArea label="What was done" name="note" rows={2} maxLength={255} required value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            <div className="span2 form-actions"><button className="button primary" type="submit" disabled={form.processing}>Mark as reviewed</button></div>
                        </form>
                    )}
                </Panel>
            )}
        </AppLayout>
    );
}
