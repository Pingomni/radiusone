import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PostButton } from '@/components/confirm';
import { Sheet } from '@/components/settings';
import { Badge } from '@/components/ui';
import type { SoftwareUpdate, UpdateStatus } from '@/settings/types';

type Live = { current: string; running: boolean; status: UpdateStatus | null; log: string[] };

/** Settings → Overview → Software update: newer release tags from GitHub, installed by the root helper ops/frweb-update. */
export function UpdateSheet({ u }: { u: SoftwareUpdate }) {
    const [live, setLive] = useState<Live>({ current: u.current, running: u.running, status: u.status, log: u.log });

    // While an update runs the portal is in maintenance mode; only the status URL answers. Poll it, then reload.
    useEffect(() => {
        if (!live.running) return;
        const timer = window.setInterval(async () => {
            try {
                const response = await fetch('/settings/update/status', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const next = (await response.json()) as Live;
                setLive(next);
                if (!next.running) {
                    window.clearInterval(timer);
                    router.reload();
                }
            } catch {
                // The web server restarts briefly during an update; try again on the next tick.
            }
        }, 3000);
        return () => window.clearInterval(timer);
    }, [live.running]);

    const check = u.check;
    const last = live.status;
    const badge = live.running
        ? <Badge tone="info">Updating</Badge>
        : check?.newer ? <Badge tone="warning">{check.latest} available</Badge> : <Badge tone="success">Up to date</Badge>;

    return (
        <Sheet title="Software update" icon="refresh" meta={badge}>
            <div className="update-sheet">
                <p>
                    Installed: <strong>RadiusOne {live.current}</strong>
                    {check && !live.running && <span className="hint"> · checked {check.at}{check.newer ? `, ${check.latest} available` : ', no newer release'}</span>}
                </p>

                {live.running && (
                    <p className="update-progress" role="status">
                        <strong>{last?.message || 'Updating…'}</strong>
                        <span className="hint"> The portal is paused for a few minutes; this page reloads when it is done.</span>
                    </p>
                )}

                {!live.running && last && last.state !== 'running' && (
                    <p className={last.state === 'ok' ? 'update-result success-text' : 'update-result danger-text'} role="status">
                        {last.message} <span className="hint">({last.at})</span>
                    </p>
                )}

                {!live.running && check?.newer && (
                    <>
                        {check.notes && (
                            <details className="update-notes" open>
                                <summary>What is new in {check.latest}</summary>
                                <pre>{check.notes}</pre>
                            </details>
                        )}
                        {check.problem && <p className="field-error">{check.problem}</p>}
                    </>
                )}

                {u.ready ? (
                    <div className="form-actions">
                        <PostButton action="/settings/update/check" label="Check for updates" className="button" disabled={live.running} />
                        {check?.newer && !check.problem && (
                            <PostButton
                                action="/settings/update/start"
                                label={`Update to ${check.latest}`}
                                className="button primary"
                                disabled={live.running}
                                confirm={{
                                    title: `Update to RadiusOne ${check.latest}?`,
                                    message: 'The database is backed up first. The portal is paused for a few minutes while the new version is installed; RADIUS keeps authenticating customers. If a step fails, the current version comes back.',
                                    confirmLabel: 'Update now',
                                }}
                            />
                        )}
                    </div>
                ) : (
                    <p className="hint">To update from here, run <code>sudo bash ops/apply-service-control.sh</code> on the server once.</p>
                )}

                {live.log.length > 0 && (
                    <details className="update-log" open={live.running}>
                        <summary>{live.running ? 'Progress' : 'Last update log'}</summary>
                        <pre className="mono">{live.log.join('\n')}</pre>
                    </details>
                )}
            </div>
        </Sheet>
    );
}
