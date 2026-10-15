import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PostButton } from './confirm';
import Icon from './Icon';
import { ChartOrMessage, type ChartPayload } from './LineChart';
import { LivePanel } from './LivePanel';
import { Badge, Panel } from './ui';

export type Gauge = { label: string; value: string; note: string; tone: string; meter: { value: number; low: number; high: number } | null };
type ChartPanel = { url: string; data: ChartPayload } | null;
export type History =
    | { available: false }
    | {
        available: true;
        range: string;
        ranges: Record<string, string>;
        interfaces: { value: string; label: string }[];
        selected: number | null;
        showFilters: boolean;
        health: ChartPanel;
        temperature: ChartPanel;
        traffic: { url: string; data: ChartPayload };
    };

export function Gauges({ gauges }: { gauges: Gauge[] }) {
    return (
        <div className="nas-gauges">
            {gauges.map((g) => (
                <div key={g.label} className={`nas-gauge ${g.tone}`.trim()}>
                    <span>{g.label}</span>
                    <strong>{g.value}</strong>
                    {g.meter && <meter min={0} max={100} low={g.meter.low} high={g.meter.high} optimum={0} value={g.meter.value} />}
                    <small>{g.note}</small>
                </div>
            ))}
        </div>
    );
}

export function Kv({ label, children }: { label: ReactNode; children: ReactNode }) {
    return <div className="kv"><span>{label}</span>{children}</div>;
}

/** "Poll now" button: same recording as the scheduled poller, for everything or one device. */
export function PollButton({ target, back, label }: { target: string; back: string; label: string }) {
    return <PostButton action="/monitoring/poll" data={{ target, return: back }} className="primary" label={<><Icon name="activity" />{label}</>} />;
}

/** Range and interface filters plus the CPU/memory, temperature and traffic charts. */
export function HistorySection({ history, pageUrl }: { history: History; pageUrl: string }) {
    if (!history.available) {
        return <Panel title="Interface traffic"><p className="hint">Apply database migration 022_nas_snmp_samples.sql to record traffic history.</p></Panel>;
    }
    const href = (params: Record<string, string | number | null>) => {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => { if (v !== null && v !== '') query.set(k, String(v)); });
        return `${pageUrl}?${query.toString()}`;
    };
    return (
        <>
            {history.showFilters && (
                <div className="toolbar traffic-filters">
                    <nav className="segmented" aria-label="Time range">
                        {Object.entries(history.ranges).map(([key, label]) => (
                            <Link key={key} href={href({ if: history.selected, range: key })} aria-current={key === history.range ? 'page' : undefined} preserveScroll>{label}</Link>
                        ))}
                    </nav>
                    {history.interfaces.length > 0 && (
                        <label className="traffic-filter-if">
                            <span>Interface</span>
                            <select
                                name="if"
                                value={history.selected === null ? '' : String(history.selected)}
                                onChange={(event) => router.get(href({ if: event.target.value, range: history.range }), {}, { preserveScroll: true })}
                            >
                                {history.interfaces.map((i) => <option key={i.value} value={i.value}>{i.label}</option>)}
                            </select>
                        </label>
                    )}
                </div>
            )}
            {history.health
                ? <LivePanel title="CPU and memory" url={history.health.url} interval={60} initial={history.health.data} className="nas-traffic" render={(d) => <ChartOrMessage payload={d} />} />
                : <Panel title="CPU and memory"><p className="hint">Apply database migration 023_nas_snmp_health_samples.sql to record CPU and memory history.</p></Panel>}
            {history.temperature
                ? <LivePanel title="Temperature" url={history.temperature.url} interval={60} initial={history.temperature.data} className="nas-traffic" render={(d) => <ChartOrMessage payload={d} />} />
                : <Panel title="Temperature"><p className="hint">Apply database migration 024_nas_snmp_temperature_samples.sql to record temperature history.</p></Panel>}
            <LivePanel title="Interface traffic" url={history.traffic.url} interval={60} initial={history.traffic.data} className="nas-traffic" render={(d) => <ChartOrMessage payload={d} />} />
        </>
    );
}

export function ToneValue({ value, tone }: { value: string; tone: string | null }) {
    return tone === null ? <strong>{value}</strong> : <Badge tone={tone}>{value}</Badge>;
}
