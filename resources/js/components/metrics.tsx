import { Link } from '@inertiajs/react';

/**
 * A key figure, built server-side by App\Http\Support\Present::metric. Shown in one strip of numbers split by hairlines:
 * label, number and an optional short line. A warning or danger tone colours the number; a link makes the cell clickable.
 */
export type Metric = { label: string; value: string; icon: string; tone: string; href: string; note?: string };

export function MetricCard({ metric }: { metric: Metric }) {
    const body = (
        <>
            <span className="stat-label">{metric.label}</span>
            <strong className="stat-value">{metric.value}</strong>
            {metric.note && <span className="stat-note">{metric.note}</span>}
        </>
    );
    const className = `stat ${metric.tone}`.trim();
    return metric.href ? <Link className={className} href={metric.href}>{body}</Link> : <div className={className}>{body}</div>;
}

export function MetricCards({ metrics }: { metrics: Metric[] }) {
    return (
        <section className="stat-strip" aria-label="Key figures">
            {metrics.map((m) => <MetricCard key={m.label} metric={m} />)}
        </section>
    );
}
