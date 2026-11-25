import { useId, useState } from 'react';
import { formatInZone, useServerTimezone } from '@/lib/clock';
import type { ChartData } from './LineChart';

const UNITS = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];

/** 3400000000 → "3.4 Gbps"; axis labels drop a trailing ".0". */
function rate(value: number | null | undefined, axis = false): string {
    if (value === null || value === undefined) return 'no data';
    let v = value;
    let i = 0;
    while (v >= 1000 && i < UNITS.length - 1) {
        v /= 1000;
        i++;
    }
    const digits = i === 0 || v >= 100 ? 0 : 1;
    const text = v.toFixed(digits);
    return `${axis ? text.replace(/\.0$/, '') : text} ${UNITS[i]}`;
}

/**
 * Download and upload over time in the style of a port graph: the latest speed in the legend, a filled
 * download line with a dot on its newest point, round hours (and the date at midnight) on the time axis,
 * and a crosshair readout for pointer and keyboard (arrow keys, Home, End, Escape).
 */
export function TrafficChart({ data }: { data: ChartData }) {
    const timeZone = useServerTimezone();
    const gradient = useId();
    const [index, setIndex] = useState<number | null>(null);
    const span = Math.max(1, data.end - data.start);
    const x = (t: number) => ((t + data.bucket / 2 - data.start) / span) * 1000;
    const y = (v: number) => 300 - (Math.min(v, data.max) / Math.max(1, data.max)) * 300;
    const hours = data.bucket >= 3600;

    // One sub-path per run of recorded buckets: time before the history began stays empty.
    const lines: Record<string, string> = {};
    let area = '';
    data.series.forEach(({ key }, s) => {
        let run: [number, number][] = [];
        let d = '';
        const flush = () => {
            if (!run.length) return;
            const coords = run.map(([px, py]) => `${px.toFixed(2)} ${py.toFixed(2)}`).join(' L');
            d += `M${coords}${run.length === 1 ? ' h0.01' : ''} `;
            if (s === 0) area += `M${run[0][0].toFixed(2)} 300 L${coords} L${run[run.length - 1][0].toFixed(2)} 300 Z `;
            run = [];
        };
        data.points.forEach((p) => {
            const v = p[s + 1];
            if (v === null) flush();
            else run.push([x(p[0] as number), y(v)]);
        });
        flush();
        lines[key] = d.trim();
    });

    let last = data.points.length - 1;
    while (last > 0 && data.points[last].slice(1).every((v) => v === null)) last -= 1;
    const latest = data.points[last];

    // Time axis: round hours (every 4 h over a day) or midnights (over a week), the date at midnight.
    const parts = (t: number) => {
        const [hour, minute] = formatInZone(t * 1000, timeZone, { hour: '2-digit', minute: '2-digit', hour12: false }).split(':').map(Number);
        return { hour: hour % 24, minute };
    };
    const ticks: { left: number; label: string }[] = [];
    for (let t = Math.ceil(data.start / 3600) * 3600; t <= data.end; t += 3600) {
        const { hour, minute } = parts(t);
        if (minute !== 0 || (hours ? hour !== 0 : hour % 4 !== 0)) continue;
        const left = ((t - data.start) / span) * 100;
        if (left < 4 || left > 96) continue;
        ticks.push({
            left,
            label: hour === 0
                ? formatInZone(t * 1000, timeZone, hours ? { weekday: 'short', day: 'numeric' } : { month: 'short', day: 'numeric' })
                : formatInZone(t * 1000, timeZone, { hour: '2-digit', minute: '2-digit', hour12: false }),
        });
    }
    const steps = [4, 3, 2, 1, 0].map((i) => ({ top: 100 - i * 25, label: rate((data.max * i) / 4, true) }));

    const show = (next: number) => setIndex(Math.max(0, Math.min(data.points.length - 1, next)));
    const point = index === null ? null : data.points[index];
    const at = point ?? latest;
    const atX = x(at[0] as number) / 10;
    const when = (t: number) => formatInZone(t * 1000, timeZone, hours
        ? { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }
        : { hour: '2-digit', minute: '2-digit', hour12: false });

    return (
        <figure className="tt">
            <figcaption className="tt-legend">
                {data.series.map((s, i) => (
                    <span key={s.key} className="tt-legend-item">
                        <i className={`tt-key tt-key-${s.key}`} aria-hidden="true" />
                        {s.label} <strong>{rate(latest[i + 1])}</strong>
                    </span>
                ))}
                <span className="tt-used">{data.series.map((s) => `${s.detail} ${s.label === 'Download' ? 'down' : 'up'}`).join(' · ')}</span>
            </figcaption>
            <div className="tt-frame">
                <div className="tt-y" aria-hidden="true">
                    {steps.map((s) => <span key={s.top} style={{ top: `${s.top}%` }}>{s.label}</span>)}
                </div>
                <div
                    className="tt-plot"
                    tabIndex={0}
                    role="img"
                    aria-label={`${data.summary} Use the arrow keys to read each point.`}
                    onPointerMove={(event) => {
                        const box = event.currentTarget.getBoundingClientRect();
                        const t = data.start + ((event.clientX - box.left) / box.width) * span - data.bucket / 2;
                        show(Math.round((t - data.start) / data.bucket));
                    }}
                    onPointerLeave={(event) => { if (document.activeElement !== event.currentTarget) setIndex(null); }}
                    onFocus={() => show(index ?? last)}
                    onBlur={() => setIndex(null)}
                    onKeyDown={(event) => {
                        const current = index ?? last;
                        if (event.key === 'Escape') return setIndex(null);
                        const next = ({ ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: data.points.length - 1 } as Record<string, number>)[event.key];
                        if (next === undefined) return;
                        event.preventDefault();
                        show(next);
                    }}
                >
                    <svg viewBox="0 0 1000 300" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" className="tt-fill-top" />
                                <stop offset="100%" className="tt-fill-bottom" />
                            </linearGradient>
                        </defs>
                        {[0, 1, 2, 3].map((i) => <line key={i} className="tt-grid" x1="0" x2="1000" y1={i * 75} y2={i * 75} />)}
                        <line className="tt-base" x1="0" x2="1000" y1="300" y2="300" />
                        {area && <path d={area.trim()} fill={`url(#${gradient})`} />}
                        {[...data.series].reverse().map((s) => <path key={s.key} className={`tt-line tt-line-${s.key}`} d={lines[s.key]} />)}
                    </svg>
                    {point && <div className="tt-cross" style={{ left: `${atX}%` }} />}
                    {data.series.map((s, i) => {
                        const v = at[i + 1];
                        return v === null ? null : <div key={s.key} className={`tt-dot tt-dot-${s.key}`} style={{ left: `${atX}%`, top: `${y(v) / 3}%` }} />;
                    })}
                    {point && (
                        <div className={`tt-tip${atX > 60 ? ' tt-tip-left' : ''}`} role="status" style={{ left: `${atX}%` }}>
                            <span className="tt-tip-time">{when(point[0] as number)}</span>
                            {data.series.map((s, i) => (
                                <span className="tt-tip-row" key={s.key}><i className={`tt-key tt-key-${s.key}`} />{s.label} <strong>{rate(point[i + 1])}</strong></span>
                            ))}
                        </div>
                    )}
                </div>
                <div className="tt-x" aria-hidden="true">
                    {ticks.map((t) => <span key={t.left} style={{ left: `${t.left}%` }}>{t.label}</span>)}
                </div>
            </div>
            <table className="sr-only">
                <caption>{data.summary}</caption>
                <thead><tr><th scope="col">Time</th>{data.series.map((s) => <th key={s.key} scope="col">{s.label}</th>)}</tr></thead>
                <tbody>
                    {data.points.filter((p) => p.slice(1).some((v) => v !== null)).map((p, r) => (
                        <tr key={r}><th scope="row">{data.tableTimes[data.points.indexOf(p)]}</th>{p.slice(1).map((v, i) => <td key={i}>{rate(v)}</td>)}</tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
