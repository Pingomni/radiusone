import { useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm';
import { Sheet } from '@/components/settings';
import { Badge, Select } from '@/components/ui';
import type { Retention } from '@/settings/types';

/** Days of history a period keeps; "Keep forever" (0) keeps everything. */
const keeps = (days: number) => (days === 0 ? Infinity : days);

export function RetentionTab({ r }: { r: Retention }) {
    const form = useForm({ sessions: String(r.periods.sessions), logins: String(r.periods.logins) });
    const ask = useConfirm();
    const label = (kind: 'sessions' | 'logins', days: string) => r.options[kind][days] ?? days;
    const stat = (s: { rows: number; oldest: string }) => `About ${s.rows.toLocaleString()} records${s.oldest ? `, oldest from ${s.oldest.slice(0, 10)}` : ''}.`;
    const last = r.stats.lastRun;

    return (
        <Sheet
            title="Data retention"
            description="How long RADIUS connection history is kept. Records older than the chosen period are deleted in a nightly cleanup."
            icon="logs"
            meta={<Badge tone={r.periods.sessions === 0 && r.periods.logins === 0 ? '' : 'warning'}>{r.periods.sessions === 0 && r.periods.logins === 0 ? 'Keeping everything' : 'Cleanup active'}</Badge>}
        >
            <form
                className="form-grid"
                onSubmit={async (event) => {
                    event.preventDefault();
                    const shorter = (['sessions', 'logins'] as const).filter((kind) => keeps(Number(form.data[kind])) < keeps(r.periods[kind]));
                    if (shorter.length) {
                        const what = shorter.map((kind) => `${kind === 'sessions' ? 'session' : 'login'} records older than ${label(kind, form.data[kind]).toLowerCase()}`).join(' and ');
                        const ok = await ask({
                            title: 'Delete older records?',
                            message: `The nightly cleanup will permanently delete ${what}. They can only be recovered from a database backup.`,
                            confirmLabel: 'Save and delete',
                        });
                        if (!ok) return;
                    }
                    form.post('/settings/retention', { preserveScroll: true });
                }}
            >
                <Select
                    label="Session records (radacct)"
                    hint={`Who was connected, when, from which IP address and how much traffic. ${stat(r.stats.sessions)} Live sessions are never deleted.`}
                    options={r.options.sessions}
                    name="sessions"
                    value={form.data.sessions}
                    error={form.errors.sessions}
                    onChange={(e) => form.setData('sessions', e.target.value)}
                />
                <Select
                    label="Login records (radpostauth)"
                    hint={`Every accepted and rejected sign-in attempt. ${stat(r.stats.logins)}`}
                    options={r.options.logins}
                    name="logins"
                    value={form.data.logins}
                    error={form.errors.logins}
                    onChange={(e) => form.setData('logins', e.target.value)}
                />
                <p className="span2 hint">
                    Check how long you are legally required to keep connection records (who had which IP address and when) before choosing a period.
                    Traffic totals on client profiles and reports only cover the records that are kept.
                </p>
                <p className="span2 hint">
                    {last
                        ? `Last cleanup ${last.at}: ${last.sessions.toLocaleString()} session and ${last.logins.toLocaleString()} login records deleted${last.finished ? '.' : '; more remain and are deleted the next night.'}`
                        : 'The cleanup runs every night at 03:45 (server time); it has not deleted anything yet.'}
                </p>
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>Save retention</button></div>
            </form>
        </Sheet>
    );
}
