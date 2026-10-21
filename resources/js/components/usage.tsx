import { useState, type KeyboardEvent } from 'react';
import { bytes } from '@/lib/format';

export interface UsageHistory {
    range: '7d' | '30d' | '12m';
    monthly: boolean;
    points: { key: string; down: number; up: number }[];
    total: { down: number; up: number };
    /** The current cap cycle, when the client's plan has a data cap. */
    cycle: { start: string; end: string; cap: number; used: number; capped: boolean; action: string; slowPlan: string | null } | null;
}

const RANGES: [UsageHistory['range'], string][] = [['7d', '7 days'], ['30d', '30 days'], ['12m', '12 months']];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "2026-10" → "Oct 2026"; days stay ISO like the rest of the portal. */
function bucketLabel(key: string, monthly: boolean): string {
    return monthly ? `${MONTHS[Number(key.slice(5, 7)) - 1]} ${key.slice(0, 4)}` : key;
}

/** Used of the plan's data cap this cycle, with the day it renews and whether the client is slowed down. */
function CapMeter({ cycle }: { cycle: NonNullable<UsageHistory['cycle']> }) {
    const percent = Math.min(999, Math.floor((cycle.used * 100) / Math.max(1, cycle.cap)));
    const tone = percent >= 100 ? 'danger' : percent >= 80 ? 'warning' : '';
    const renews = new Date(`${cycle.end}T00:00:00Z`);
    renews.setUTCDate(renews.getUTCDate() + 1);
    return (
        <div className="usage-cap">
            <p>
                <strong>{bytes(cycle.used)}</strong> of {bytes(cycle.cap)} this cycle <span className="usage-cap-percent">{percent}%</span>
            </p>
            <div className={`usage-cap-bar ${tone}`.trim()} role="meter" aria-label="Data cap used this cycle" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.min(100, percent)}>
                <span style={{ width: `${Math.min(100, percent)}%` }} />
            </div>
            <p className="usage-cap-note">
                {cycle.capped
                    ? <span className="badge warning">Slowed down{cycle.slowPlan ? ` to ${cycle.slowPlan}` : ''}</span>
                    : cycle.action === 'throttle' ? 'Slows down at 100%.' : 'Notify only at 100%.'}
                {' '}<span className="usage-cap-renews">Renews {renews.toISOString().slice(0, 10)}.</span>
            </p>
        </div>
    );
}

/** Bar with only its top corners rounded: the bottom stays square on the baseline (or on the bar below). */
function topRounded(x: number, y: number, w: number, h: number, r: number): string {
    const rr = Math.min(r, w / 2, h);
    return `M${x},${y + h} V${y + rr} Q${x},${y} ${x + rr},${y} H${x + w - rr} Q${x + w},${y} ${x + w},${y + rr} V${y + h} Z`;
}

/**
 * Download and upload per day or month for one client: stacked bars (download below, upload on top),
 * totals in the legend, and the hovered or focused bar's values in the readout line.
 */
export function UsageCard({ username, initial }: { username: string; initial: UsageHistory }) {
    const [data, setData] = useState(initial);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState<number | null>(null);

    const load = async (range: UsageHistory['range']) => {
        if (range === data.range || loading) return;
        setLoading(true);
        setFailed(false);
        try {
            const response = await fetch(`/clients/${encodeURIComponent(username)}/usage?range=${range}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(String(response.status));
            setData(await response.json());
            setActive(null);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    const points = data.points;
    const [width, height, top, bottom] = [276, 112, 14, 18];
    const plot = height - top - bottom;
    const max = Math.max(1, ...points.map((p) => p.down + p.up));
    const slot = width / points.length;
    const gap = points.length > 12 ? 2 : 6;
    const barWidth = Math.max(2, slot - gap);
    const scale = (v: number) => (v / max) * plot;
    const empty = data.total.down + data.total.up === 0;
    const shown = active === null ? null : points[active];

    const onKey = (event: KeyboardEvent<SVGSVGElement>) => {
        const moves: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1 };
        if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            setActive(event.key === 'Home' ? 0 : points.length - 1);
        } else if (moves[event.key]) {
            event.preventDefault();
            setActive((current) => Math.min(points.length - 1, Math.max(0, (current ?? points.length) + moves[event.key])));
        }
    };

    return (
        <section className="panel usage-card" aria-labelledby="usage-title" aria-busy={loading}>
            <div className="panel-head">
                <h2 id="usage-title">Usage</h2>
                <div className="segmented" role="group" aria-label="Usage period">
                    {RANGES.map(([key, label]) => (
                        <button key={key} type="button" className={key === data.range ? 'active' : undefined} aria-pressed={key === data.range} onClick={() => void load(key)}>{label}</button>
                    ))}
                </div>
            </div>
            <div className="usage-body">
                <ul className="usage-legend">
                    <li><span className="usage-swatch usage-down" aria-hidden="true" />Download <strong>{bytes(data.total.down)}</strong></li>
                    <li><span className="usage-swatch usage-up" aria-hidden="true" />Upload <strong>{bytes(data.total.up)}</strong></li>
                </ul>
                {data.cycle && <CapMeter cycle={data.cycle} />}
                {empty ? (
                    <p className="usage-empty">No traffic recorded in this period.</p>
                ) : (
                    <>
                        <p className="usage-readout" aria-live="polite">
                            {shown
                                ? <><span>{bucketLabel(shown.key, data.monthly)}</span> Download {bytes(shown.down)} · Upload {bytes(shown.up)}</>
                                : <span>Select a bar to see its {data.monthly ? 'month' : 'day'}.</span>}
                        </p>
                        <svg
                            className="usage-chart"
                            viewBox={`0 0 ${width} ${height}`}
                            role="img"
                            aria-label={`Download and upload per ${data.monthly ? 'month' : 'day'}. Use the left and right arrow keys to read each bar.`}
                            tabIndex={0}
                            onKeyDown={onKey}
                            onMouseLeave={() => setActive(null)}
                            onBlur={() => setActive(null)}
                        >
                            <line className="usage-grid" x1="0" y1={top} x2={width} y2={top} />
                            <text className="usage-axis" x="0" y={top - 4}>{bytes(max)}</text>
                            {points.map((p, i) => {
                                const x = i * slot + (slot - barWidth) / 2;
                                const down = scale(p.down);
                                const up = scale(p.up);
                                const base = height - bottom;
                                // A 2px surface gap separates the two segments when both are tall enough to show it.
                                const split = down > 0 && up > 0 ? 2 : 0;
                                return (
                                    <g key={p.key} className={active === i ? 'is-active' : undefined}>
                                        {down > 0 && <path className="usage-bar usage-down" d={up > 0 ? `M${x},${base} V${base - down} H${x + barWidth} V${base} Z` : topRounded(x, base - down, barWidth, down, 2)} />}
                                        {up > 0 && <path className="usage-bar usage-up" d={topRounded(x, base - down - split - up, barWidth, up, 2)} />}
                                        <rect className="usage-hit" x={i * slot} y="0" width={slot} height={height} onMouseEnter={() => setActive(i)} onClick={() => setActive(i)} />
                                    </g>
                                );
                            })}
                            <line className="usage-baseline" x1="0" y1={height - bottom} x2={width} y2={height - bottom} />
                            <text className="usage-axis" x="0" y={height - 4}>{bucketLabel(points[0].key, data.monthly)}</text>
                            <text className="usage-axis" x={width} y={height - 4} textAnchor="end">{bucketLabel(points[points.length - 1].key, data.monthly)}</text>
                        </svg>
                        <table className="sr-only">
                            <caption>Usage per {data.monthly ? 'month' : 'day'}</caption>
                            <thead><tr><th scope="col">{data.monthly ? 'Month' : 'Day'}</th><th scope="col">Download</th><th scope="col">Upload</th></tr></thead>
                            <tbody>{points.map((p) => <tr key={p.key}><th scope="row">{bucketLabel(p.key, data.monthly)}</th><td>{bytes(p.down)}</td><td>{bytes(p.up)}</td></tr>)}</tbody>
                        </table>
                    </>
                )}
                {failed && <p className="field-error">Could not load this period. Try again.</p>}
                <p className="usage-note">Updated every 5 minutes from RADIUS accounting.</p>
            </div>
        </section>
    );
}
