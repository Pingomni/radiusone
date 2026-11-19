import { Badge } from '@/components/ui';

export type OnuRow = {
    id: number; position: string; sn: string; description: string; model: string; profile: string; status: string; downCause: string;
    lastUp: string | null; lastDown: string | null; rx: number | null; oltRx: number | null; distance: number | null; macs: number | null;
    temperature: number | null; seen: string; clients: string[]; active: boolean | null; serviceProfile: string;
};

export function OnuStatus({ status }: { status: string }) {
    if (status === 'online') return <Badge tone="success">Online</Badge>;
    if (status === 'offline') return <Badge tone="danger">Offline</Badge>;
    return <Badge>Unknown</Badge>;
}

/** GPON's usual working range is about -8 to -27 dBm: outside it the number is flagged, with words as well as colour. Each OLT can set its own levels. */
export function OnuPower({ dbm, weak = -27, veryWeak = -30 }: { dbm: number | null; weak?: number; veryWeak?: number }) {
    if (dbm === null) return <>-</>;
    const text = `${dbm.toFixed(2)} dBm`;
    if (dbm < veryWeak) return <Badge tone="danger">{text} very weak</Badge>;
    if (dbm < weak) return <Badge tone="warning">{text} weak</Badge>;
    if (dbm > -8) return <Badge tone="warning">{text} too strong</Badge>;
    return <span className="mono">{text}</span>;
}

const SCALE_MIN = -32;
const SCALE_MAX = -5;
const SCALE_STRONG = -8;

/** Where a reading sits against the OLT's levels: very weak, weak, too strong or good. */
export function signalLevel(dbm: number | null, weak = -27, veryWeak = -30): 'none' | 'danger' | 'warning' | 'good' {
    if (dbm === null) return 'none';
    if (dbm < veryWeak) return 'danger';
    if (dbm < weak || dbm > SCALE_STRONG) return 'warning';
    return 'good';
}

/**
 * The fibre signal scale: one bar from -32 to -5 dBm in red (very weak), amber (weak), green and amber again (too strong),
 * with a marker at the reading. The same part on the client, OLT and ONU pages; the zones follow the OLT's own levels.
 */
export function SignalScale({ dbm, weak = -27, veryWeak = -30 }: { dbm: number | null; weak?: number; veryWeak?: number }) {
    const span = SCALE_MAX - SCALE_MIN;
    const pct = (v: number) => (Math.min(SCALE_MAX, Math.max(SCALE_MIN, v)) - SCALE_MIN) / span * 100;
    const zones: [string, number][] = [
        ['zone-danger', pct(veryWeak)],
        ['zone-warning', pct(weak) - pct(veryWeak)],
        ['zone-good', pct(SCALE_STRONG) - pct(weak)],
        ['zone-warning', 100 - pct(SCALE_STRONG)],
    ];
    return (
        <span className={`signal-scale${dbm === null ? ' is-empty' : ''}`} aria-hidden="true">
            {zones.map(([zone, width], i) => <i key={i} className={zone} style={{ width: `${width}%` }} />)}
            {dbm !== null && <b style={{ left: `${pct(dbm)}%` }} />}
        </span>
    );
}

const LEVEL_WORDS = { none: 'no reading', danger: 'very weak', warning: 'weak', good: 'good' } as const;

/** The scale with the number beside it, coloured and worded by level. `second` adds a small line under the number. */
export function SignalReading({ dbm, weak = -27, veryWeak = -30, second }: { dbm: number | null; weak?: number; veryWeak?: number; second?: string }) {
    const level = signalLevel(dbm, weak, veryWeak);
    const words = dbm !== null && dbm > SCALE_STRONG ? 'too strong' : LEVEL_WORDS[level];
    return (
        <span className={`signal-reading is-${level}`} title={dbm === null ? 'No reading' : `${dbm.toFixed(2)} dBm, ${words}`}>
            <SignalScale dbm={dbm} weak={weak} veryWeak={veryWeak} />
            <strong>
                {dbm === null ? '-' : dbm.toFixed(1).replace('-', '\u2212')}
                <span className="sr-only"> dBm, {words}</span>
                {second && <small>{second}</small>}
            </strong>
        </span>
    );
}
