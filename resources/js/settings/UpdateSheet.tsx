import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PostButton } from '@/components/confirm';
import { Sheet } from '@/components/settings';
import { Badge } from '@/components/ui';
import type { SoftwareUpdate, UpdateStatus } from '@/settings/types';

type Live = { current: string; running: boolean; status: UpdateStatus | null; log: string[] };

/** RadiusOne's repository: its CHANGELOG at a release tag is that release's notes. */
const REPO = 'https://github.com/n-geraldo/radiusone';

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
    const available = Boolean(check?.newer && !live.running);
    // Where a release's notes are read: the changelog at its tag (RadiusOne has no separate release pages).
    const notesUrl = (tag: string) => `${REPO}/blob/${tag}/CHANGELOG.md`;
    const failed = !live.running && last?.state === 'failed';
    const finished = !live.running && last?.state === 'ok' && last.to === live.current && last.from !== live.current;
    const showLog = live.log.length > 0 && (live.running || failed || finished);
    const badge = live.running
        ? <Badge tone="warning">updating</Badge>
        : available ? <Badge tone="warning">update available</Badge> : check ? <Badge tone="success">up to date</Badge> : undefined;

    return (
        <Sheet title="Software update" icon="refresh" meta={badge}>
            <div className="update-sheet">
                <p>Installed: <strong>RadiusOne {live.current}</strong></p>
                {live.running && (
                    <p role="status">{last?.message || `Updating RadiusOne to ${last?.to ?? check?.latest}…`} The portal is paused for a few minutes; this page reloads when it is done.</p>
                )}
                {available && check && <p><strong>RadiusOne {check.latest} is available</strong> (checked {check.at}).</p>}
                {!live.running && check && !check.newer && <p className="success-text">RadiusOne is up to date (the latest release is {check.latest || live.current}).</p>}
                {failed && <p className="danger-text" role="status">{last?.message} ({last?.at}): RadiusOne {live.current} runs. Its log is below.</p>}
                {finished && <p className="success-text" role="status">{last?.message} ({last?.at})</p>}
                {available && check?.problem && <p className="danger-text">{check.problem}</p>}
                {!u.ready && <p className="hint">To update from here, run <code>sudo bash ops/apply-service-control.sh</code> on the server once.</p>}
                {showLog
                    ? <pre aria-label="Update log">{live.log.join('\n')}</pre>
                    : available && check?.notes && <pre aria-label={`What is new in ${check.latest}`}>{check.notes}</pre>}
                <div className="form-actions">
                    {u.ready && available && check && !check.problem && (
                        <PostButton
                            action="/settings/update/start"
                            label={`Install RadiusOne ${check.latest}`}
                            className="button primary"
                            disabled={live.running}
                            confirm={{
                                title: `Install RadiusOne ${check.latest}?`,
                                message: 'The database is backed up first. The portal is paused for a few minutes while the new version is installed; RADIUS keeps authenticating customers. If a step fails, the current version comes back.',
                                confirmLabel: 'Install update',
                            }}
                        />
                    )}
                    {u.ready && <PostButton action="/settings/update/check" label="Check for updates" className="button" disabled={live.running} />}
                    {check?.tag && <a className="button" href={notesUrl(check.tag)} target="_blank" rel="noopener noreferrer">Release notes</a>}
                </div>
            </div>
        </Sheet>
    );
}
