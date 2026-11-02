import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { DataTable } from '@/components/table';
import { Badge, Empty } from '@/components/ui';

type Check = { key: string; label: string; state: 'ok' | 'warn' | 'fail' | 'info'; detail: string };
type RecentError = { title: string; fix: string; count: number; at: string };
export type UnknownRouter = { address: string; how: string; count: number; at: string };
type Capture = {
    state: 'none' | 'running' | 'done' | 'failed';
    startedAt?: string;
    remaining?: number;
    counts?: Record<string, number>;
    others?: { address: string; requests: number }[];
    verdict?: { tone: string; title: string; detail: string };
};
type Data = { checks: Check[]; errors: RecentError[]; unknown: UnknownRouter[]; capture: Capture; canTest: boolean };

const STATES: Record<Check['state'], { tone: string; text: string }> = {
    ok: { tone: 'success', text: 'OK' },
    warn: { tone: 'warning', text: 'Check' },
    fail: { tone: 'danger', text: 'Problem' },
    info: { tone: 'info', text: 'Info' },
};

/** Addresses sending RADIUS requests that are not in the NAS list. `action` is the fix offered for one address. */
export function UnknownRouterTable({ rows, action }: { rows: UnknownRouter[]; action?: (address: string) => React.ReactNode }) {
    return (
        <DataTable
            rows={rows}
            rowKey={(r) => r.address}
            columns={[
                { header: 'Address', cell: (r) => <span className="mono">{r.address}</span> },
                { header: 'What happened', className: 'wrap', cell: (r) => r.how },
                { header: 'Requests in 24 hours', cell: (r) => r.count },
                { header: 'Last', cell: (r) => r.at },
                ...(action ? [{ header: '', cell: (r: UnknownRouter) => action(r.address) }] : []),
            ]}
        />
    );
}

function CaptureResult({ capture }: { capture: Capture }) {
    if (capture.state === 'none') return <p className="hint">Press Start, then try a login on the router. The test checks the requests against the saved shared secret, and deletes the packets after 15 minutes.</p>;
    const c = capture.counts ?? {};
    const running = capture.state === 'running';
    return (
        <>
            {running && <p className="hint" role="status">Listening for {capture.remaining} more seconds. Try a login on the router now.</p>}
            {capture.state === 'failed' && <p className="hint" role="status">The test stopped without finishing, so these results may be incomplete.</p>}
            {!running && capture.verdict && (
                <div className={`nas-verdict ${capture.verdict.tone}`} role="status">
                    <strong>{capture.verdict.title}</strong>
                    <p>{capture.verdict.detail}</p>
                </div>
            )}
            <div className="kv"><span>Login requests received</span><strong>{c.logins ?? 0}</strong></div>
            <div className="kv"><span>Accepted / rejected / challenged</span><strong>{c.accepted ?? 0} / {c.rejected ?? 0} / {c.challenged ?? 0}</strong></div>
            <div className="kv"><span>Shared secret on signed requests</span><strong>{(c.secretBad ?? 0) > 0 ? `${c.secretBad} wrong` : (c.secretOk ?? 0) > 0 ? 'Correct' : 'No signed requests yet'}</strong></div>
            <div className="kv"><span>Accounting requests / replies</span><strong>{c.accounting ?? 0} / {c.accountingReplies ?? 0}</strong></div>
            {!!capture.others?.length && (
                <div className="kv"><span>Other addresses not in your NAS list</span><strong className="mono">{capture.others.map((o) => `${o.address} (${o.requests})`).join(', ')}</strong></div>
            )}
            <p className="hint">Started at {capture.startedAt}.</p>
        </>
    );
}

export default function NasDiagnostics({ nasId }: { nasId: number }) {
    const [data, setData] = useState<Data | null>(null);
    const [failed, setFailed] = useState(false);

    const load = useCallback(async () => {
        try {
            const response = await fetch(`/nas/${nasId}/diagnostics`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(String(response.status));
            setData((await response.json()) as Data);
            setFailed(false);
        } catch {
            setFailed(true);
        }
    }, [nasId]);

    useEffect(() => { void load(); }, [load]);

    const running = data?.capture.state === 'running';
    useEffect(() => {
        if (!running) return undefined;
        const timer = window.setInterval(() => { void load(); }, 2000);
        return () => window.clearInterval(timer);
    }, [running, load]);

    if (!data) return failed ? <Empty tone="danger">The diagnostics could not be loaded. Try again in a moment.</Empty> : <p className="hint">Checking…</p>;

    return (
        <div className="nas-diagnostics">
            <div className="nas-diagnostics-head">
                <h3>Connection checklist</h3>
                <button type="button" className="button" onClick={() => void load()}>Check again</button>
            </div>
            {failed && <p className="hint" role="alert">The last refresh failed, so this may be out of date.</p>}
            <DataTable
                rows={data.checks}
                rowKey={(c) => c.key}
                columns={[
                    { header: 'Check', className: 'wrap', cell: (c) => c.label },
                    { header: 'Result', cell: (c) => <Badge tone={STATES[c.state].tone}>{STATES[c.state].text}</Badge> },
                    { header: 'Details', className: 'wrap', cell: (c) => c.detail },
                ]}
            />

            <div className="nas-diagnostics-head">
                <h3>60-second packet test</h3>
                {data.canTest && (
                    <button
                        type="button"
                        className="button primary"
                        disabled={running}
                        onClick={() => router.post(`/nas/${nasId}/diagnostics/capture`, {}, { preserveScroll: true, onSuccess: () => void load() })}
                    >
                        {running ? 'Listening…' : 'Start'}
                    </button>
                )}
            </div>
            <CaptureResult capture={data.capture} />

            <div className="nas-diagnostics-head"><h3>Recent errors for this NAS</h3></div>
            {data.errors.length ? (
                <DataTable
                    rows={data.errors}
                    columns={[
                        { header: 'Problem', className: 'wrap', cell: (e) => e.title },
                        { header: 'How to fix it', className: 'wrap', cell: (e) => e.fix },
                        { header: 'Times', cell: (e) => e.count },
                        { header: 'Last', cell: (e) => e.at },
                    ]}
                />
            ) : <Empty>No errors for this NAS in the last 24 hours.</Empty>}

            {data.unknown.length > 0 && (
                <>
                    <div className="nas-diagnostics-head"><h3>Addresses not in your NAS list</h3></div>
                    <p className="hint">If one of these is this router, it sends from a different address than the one saved. Addresses you don&apos;t recognise may be scanners on the internet: don&apos;t add those.</p>
                    <UnknownRouterTable
                        rows={data.unknown}
                        action={data.canTest ? (address) => <Link className="link" href={`/nas/${nasId}/edit?tab=device&nasname=${encodeURIComponent(address)}`}>Use for this NAS</Link> : undefined}
                    />
                </>
            )}
        </div>
    );
}

/** The NAS list's warning: addresses knocking at RADIUS that are not routers here yet. */
export function UnknownRouters({ canAdd }: { canAdd: boolean }) {
    const [rows, setRows] = useState<UnknownRouter[]>([]);
    useEffect(() => {
        const controller = new AbortController();
        fetch('/nas/unknown-routers', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((response) => (response.ok ? response.json() : []))
            .then((next: UnknownRouter[]) => setRows(next))
            .catch(() => undefined);
        return () => controller.abort();
    }, []);
    if (!rows.length) return null;
    return (
        <section className="panel">
            <h2>Addresses not in your NAS list</h2>
            <p className="hint">These sent RADIUS requests in the last 24 hours and were refused. Add the ones that are your routers. Addresses you don&apos;t recognise may be scanners on the internet: don&apos;t add those.</p>
            <UnknownRouterTable
                rows={rows}
                action={canAdd ? (address) => <Link className="link" href={`/nas/create?nasname=${encodeURIComponent(address)}`}>Add as NAS</Link> : undefined}
            />
        </section>
    );
}
