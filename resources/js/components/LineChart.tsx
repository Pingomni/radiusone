import { useState } from 'react';
import { formatInZone, useServerTimezone } from '@/lib/clock';
import { DataTable } from './table';
import { Empty } from './ui';

export interface ChartData {
    title: string;
    note: string;
    unit: 'bps' | 'pct' | 'celsius';
    area: string | null;
    summary: string;
    footnote: string | null;
    start: number;
    end: number;
    bucket: number;
    min: number;
    max: number;
    week: boolean;
    yLabels: string[];
    xLabels: string[];
    tableTimes: string[];
    series: { key: string; label: string; detail: string }[];
    /** [unix time, value per series (null = no data)]. */
    points: (number | null)[][];
}

/** Chart endpoint payload: a chart, or a sentence explaining why there is none yet. */
export type ChartPayload = { chart: ChartData } | { message: string };

const BPS = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s', 'Tbit/s'];

function formatValue(value: number | null | undefined, unit: ChartData['unit']): string {
    if (value === null || value === undefined) return 'no data';
    if (unit === 'pct') return `${value.toFixed(1)} %`;
    if (unit === 'celsius') return `${value.toFixed(1)} °C`;
    let v = value;
    let i = 0;
    while (v >= 1000 && i < BPS.length - 1) {
        v /= 1000;
        i++;
    }
    return `${v.toFixed(i ? 1 : 0)} ${BPS[i]}`;
}

function tableValue(value: number | null, unit: ChartData['unit']): string {
    return value === null ? '-' : formatValue(value, unit);
}

/**
 * Responsive line chart (legacy nasLineChart): an SVG plot that stretches with the page, HTML axis
 * labels, a legend, gaps for missing buckets, a crosshair readout for pointer and keyboard
 * (arrow keys, Home, End, Escape) and a table view. One y-axis only.
 */
export function LineChart({ data }: { data: ChartData }) {
    const timeZone = useServerTimezone();
    const [index, setIndex] = useState<number | null>(null);
    const span = Math.max(1, data.end - data.start);
    const x = (t: number) => ((t + data.bucket / 2 - data.start) / span) * 1000;
    const y = (v: number) => 300 - ((Math.max(data.min, Math.min(v, data.max)) - data.min) / (data.max - data.min)) * 300;
    const percentX = (t: number) => x(t) / 10;
    const percentY = (v: number) => y(v) / 3;

    // One sub-path per run of consecutive buckets, so outages show as gaps.
    const paths: Record<string, string> = {};
    let area = '';
    data.series.forEach(({ key }, s) => {
        let run: [number, number][] = [];
        let d = '';
        const flush = () => {
            if (!run.length) return;
            const coords = run.map(([px, py]) => `${px.toFixed(2)} ${py.toFixed(2)}`).join(' L');
            d += `M${coords}${run.length === 1 ? ' h0.01' : ''} `;
            if (key === data.area) area += `M${run[0][0].toFixed(2)} 300 L${coords} L${run[run.length - 1][0].toFixed(2)} 300 Z `;
            run = [];
        };
        data.points.forEach((point) => {
            const value = point[s + 1];
            if (value === null) flush();
            else run.push([x(point[0] as number), y(value)]);
        });
        flush();
        paths[key] = d.trim();
    });

    const lastWithData = () => {
        let last = data.points.length - 1;
        while (last > 0 && data.points[last].slice(1).every((v) => v === null)) last -= 1;
        return last;
    };
    const show = (next: number) => setIndex(Math.max(0, Math.min(data.points.length - 1, next)));
    const point = index === null ? null : data.points[index];
    const pointX = point ? percentX(point[0] as number) : 0;
    const timeLabel = (t: number) => formatInZone(t * 1000, timeZone, data.week
        ? { weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }
        : { hour: '2-digit', minute: '2-digit', hour12: false });

    const rows = data.points
        .map((p, i) => ({ time: data.tableTimes[i], values: p.slice(1) as (number | null)[] }))
        .filter((r) => r.values.some((v) => v !== null))
        .reverse();

    return (
        <figure className="tc">
            <figcaption className="tc-legend">
                <span className="tc-title">{data.title} <small>{data.note}</small></span>
                {data.series.map((s) => (
                    <span className="tc-legend-item" key={s.key}><i className={`tc-key tc-key-${s.key}`} aria-hidden="true" /><strong>{s.label}</strong><span>{s.detail}</span></span>
                ))}
            </figcaption>
            <div className="tc-frame">
                <div className="tc-y" aria-hidden="true">{data.yLabels.map((l, i) => <span key={i}>{l}</span>)}</div>
                <div
                    className="tc-plot"
                    tabIndex={0}
                    role="img"
                    aria-label={`${data.summary} Use the arrow keys to read each point.`}
                    onPointerMove={(event) => {
                        const box = event.currentTarget.getBoundingClientRect();
                        const t = data.start + ((event.clientX - box.left) / box.width) * span - data.bucket / 2;
                        show(Math.round((t - data.start) / data.bucket));
                    }}
                    onPointerLeave={(event) => { if (document.activeElement !== event.currentTarget) setIndex(null); }}
                    onFocus={() => show(index ?? lastWithData())}
                    onBlur={() => setIndex(null)}
                    onKeyDown={(event) => {
                        const current = index ?? data.points.length - 1;
                        if (event.key === 'Escape') return setIndex(null);
                        const next = ({ ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: data.points.length - 1 } as Record<string, number>)[event.key];
                        if (next === undefined) return;
                        event.preventDefault();
                        show(next);
                    }}
                >
                    <svg viewBox="0 0 1000 300" preserveAspectRatio="none" aria-hidden="true">
                        {[0, 1, 2, 3, 4].map((i) => <line key={i} className="tc-grid" x1="0" x2="1000" y1={i * 75} y2={i * 75} />)}
                        {area && <path className={`tc-area-${data.area}`} d={area.trim()} />}
                        {[...data.series].reverse().map((s) => <path key={s.key} className={`tc-line tc-line-${s.key}`} d={paths[s.key]} />)}
                    </svg>
                    {point && (
                        <>
                            <div className="tc-cross" style={{ left: `${pointX}%` }} />
                            {data.series.map((s, i) => {
                                const value = point[i + 1];
                                return value === null ? null : <div key={s.key} className={`tc-dot tc-dot-${s.key}`} style={{ left: `${pointX}%`, top: `${percentY(value)}%` }} />;
                            })}
                            <div className={`tc-tip${pointX > 60 ? ' tc-tip-left' : ''}`} role="status" style={{ left: `${pointX}%` }}>
                                <span className="tc-tip-time">{timeLabel(point[0] as number)}</span>
                                {data.series.map((s, i) => (
                                    <span className="tc-tip-row" key={s.key}><i className={`tc-key tc-key-${s.key}`} /><strong>{formatValue(point[i + 1], data.unit)}</strong> {s.label}</span>
                                ))}
                            </div>
                        </>
                    )}
                </div>
                <div className="tc-x" aria-hidden="true">{data.xLabels.map((l, i) => <span key={i}>{l}</span>)}</div>
            </div>
            {data.footnote && <p className="hint tc-footnote">{data.footnote}</p>}
            <details className="tc-table">
                <summary>Show data table</summary>
                <DataTable
                    rows={rows}
                    columns={[
                        { header: 'Time', cell: (r) => r.time },
                        ...data.series.map((s, i) => ({ header: s.label, cell: (r: (typeof rows)[number]) => tableValue(r.values[i], data.unit) })),
                    ]}
                />
            </details>
        </figure>
    );
}

export function ChartOrMessage({ payload }: { payload: ChartPayload }) {
    return 'chart' in payload ? <LineChart data={payload.chart} /> : <Empty>{payload.message}</Empty>;
}
