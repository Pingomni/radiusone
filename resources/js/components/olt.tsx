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
