import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { DeleteButton, PostButton } from '@/components/confirm';
import { OnuPower, OnuStatus, type OnuRow } from '@/components/olt';
import { DataTable } from '@/components/table';
import { Badge, Field, Panel, Time } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

type Wan = { name: string; service: string; type: string; status: string; access: string; ip: string; mask: string; gateway: string; dns: string; vlan: string; mac: string };
type Lan = { port: number; type: string; speed: string; duplex: string; up: boolean };
type Live = {
    state: { run: string; active: boolean; config: string; match: string; online: string; uptime: string; cpu: string; memory: string; temperature: string; downCause: string; ip: string; lineProfile: string; serviceProfile: string };
    optical: { rx: string; tx: string; oltRx: string; temperature: string; voltage: string; bias: string };
    software: { model: string; version: string; mac: string };
    wans: Wan[];
    lan: Lan[];
};
type Answer = { ok: true; data: Live } | { ok: false; error: string };

const Row = ({ label, children }: { label: string; children: ReactNode }) => (
    <div className="kv"><span>{label}</span><strong>{children}</strong></div>
);
const dash = (value: string, unit = '') => (value ? `${value}${unit}` : '-');
const power = (value: string) => (value && !Number.isNaN(Number(value)) ? <OnuPower dbm={Number(value)} /> : '-');

function LiveDetails({ url }: { url: string }) {
    const [answer, setAnswer] = useState<Answer | null>(null);
    const [busy, setBusy] = useState(false);
    const read = useCallback(async () => {
        setBusy(true);
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            setAnswer(response.ok ? ((await response.json()) as Answer) : { ok: false, error: `The portal answered ${response.status}. Reload the page and try again.` });
        } catch {
            setAnswer({ ok: false, error: 'The portal could not be reached.' });
        } finally {
            setBusy(false);
        }
    }, [url]);
    useEffect(() => { void read(); }, [read]);

    const refresh = <button type="button" className="button" disabled={busy} onClick={() => void read()}>{busy ? 'Asking the OLT…' : 'Read again'}</button>;
    if (!answer) return <Panel title="Right now"><p className="hint" role="status">Asking the OLT. This takes a few seconds.</p></Panel>;
    if (!answer.ok) return <Panel title="Right now" actions={refresh}><p className="flash warning" role="alert">{answer.error}</p></Panel>;
    const { state, optical, software, wans, lan } = answer.data;
    return (
        <>
            <Panel title="Right now" actions={refresh}>
                <Row label="State">{!state.active ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={state.run} />}</Row>
                <Row label="Online for">{dash(state.online)}</Row>
                <Row label="Running since restart">{dash(state.uptime)}</Row>
                <Row label="Signal at the ONU / at the OLT">{power(optical.rx)} / {power(optical.oltRx)}</Row>
                <Row label="ONU transmit power">{dash(optical.tx, ' dBm')}</Row>
                <Row label="CPU / memory">{dash(state.cpu)} / {dash(state.memory)}</Row>
                <Row label="Temperature: device / optical module">{dash(state.temperature)} / {dash(optical.temperature, ' °C')}</Row>
                <Row label="Configuration on the ONU">{dash(state.config)}{state.match ? `, profile ${state.match}` : ''}</Row>
                <Row label="Software">{dash(software.version)}</Row>
                <Row label="Model / MAC"><span className="mono">{dash(software.model)} / {dash(software.mac)}</span></Row>
                <Row label="Reason it last went offline">{dash(state.downCause)}</Row>
            </Panel>
            <Panel title="Internet connections on the ONU">
                <DataTable
                    rows={wans}
                    rowKey={(w) => w.name + w.mac}
                    emptyText="The ONU reports no connection of its own. That is normal for an ONU in bridge mode, or one that is offline."
                    columns={[
                        { header: 'Name', className: 'wrap', cell: (w) => w.name || '-' },
                        { header: 'Status', cell: (w) => (w.status ? <Badge tone={w.status.toLowerCase() === 'connected' ? 'success' : 'warning'}>{w.status}</Badge> : '-') },
                        { header: 'Type', cell: (w) => [w.type, w.access].filter(Boolean).join(', ') || '-' },
                        { header: 'IP address', cell: (w) => <span className="mono">{w.ip || '-'}</span> },
                        { header: 'Gateway', cell: (w) => <span className="mono">{w.gateway || '-'}</span> },
                        { header: 'DNS', cell: (w) => <span className="mono">{w.dns || '-'}</span> },
                        { header: 'VLAN', cell: (w) => w.vlan || '-' },
                        { header: 'MAC', cell: (w) => <span className="mono">{w.mac || '-'}</span> },
                    ]}
                />
            </Panel>
            <Panel title="LAN ports">
                <DataTable
                    rows={lan}
                    rowKey={(p) => p.port}
                    emptyText="The ONU did not report its LAN ports. It may be offline."
                    columns={[
                        { header: 'Port', cell: (p) => `${p.type} ${p.port}` },
                        { header: 'Link', cell: (p) => (p.up ? <Badge tone="success">Up</Badge> : <Badge>Down</Badge>) },
                        { header: 'Speed', cell: (p) => (p.speed ? `${p.speed} Mbit/s` : '-') },
                        { header: 'Duplex', cell: (p) => p.duplex || '-' },
                    ]}
                />
            </Panel>
        </>
    );
}

export default function OnuPage({ olt, onu, clients }: { olt: { id: number; name: string; canRead: boolean; canChange: boolean }; onu: OnuRow; clients: { username: string; name: string }[] }) {
    const can = useCan();
    const base = `/olts/${olt.id}/onus/${onu.id}`;
    const [description, setDescription] = useState(onu.description);
    const mayChange = can('nas.manage') && olt.canChange;
    const off = onu.active === false;
    return (
        <AppLayout title={`ONU ${onu.sn}`}>
            <div className="toolbar">
                <Link className="button" href={`/olts/${olt.id}`}>{olt.name}</Link>
                {mayChange && (
                    <>
                        <PostButton action={`${base}/reboot`} label="Restart" className="button" disabled={off || onu.status === 'offline'} confirm={{ title: 'Restart this ONU?', message: `${onu.description || onu.sn} loses its connection for a minute or two.`, confirmLabel: 'Restart' }} />
                        {off
                            ? <PostButton action={`${base}/enable`} label="Turn on" className="button" />
                            : <PostButton action={`${base}/disable`} label="Turn off" className="button" confirm={{ title: 'Turn this ONU off?', message: `${onu.description || onu.sn} loses its connection until you turn the ONU on again. Its settings stay on the OLT.`, confirmLabel: 'Turn off' }} />}
                        {can('danger.delete') && (
                            <DeleteButton action={`${base}/delete`} label="Remove from the OLT" title={`Remove ${onu.sn} from the OLT?`} message="The ONU and its service ports are deleted on the OLT, and its client is unlinked. The customer is offline until the ONU is added again." typeToConfirm={onu.sn} />
                        )}
                    </>
                )}
            </div>
            {can('nas.manage') && !olt.canChange && <p className="hint">Changes are turned off for this OLT, so this page only reads. To restart, turn off, rename or remove ONUs here, turn on <Link href={`/olts/${olt.id}/edit`}>Allow changes from the portal</Link>.</p>}
            <Panel title="ONU">
                <Row label="Status">{off ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={onu.status} />}</Row>
                <Row label="OLT and port"><Link href={`/olts/${olt.id}?q=${encodeURIComponent(onu.sn)}`}>{olt.name}</Link> <span className="mono">{onu.position}</span></Row>
                <Row label="Serial number"><span className="mono">{onu.sn}</span></Row>
                <Row label="Client">{clients.length ? clients.map((c) => <div key={c.username}><Link href={`/clients/${encodeURIComponent(c.username)}/edit?tab=onu`}>{c.username}</Link>{c.name ? ` (${c.name})` : ''}</div>) : 'Not linked to a client'}</Row>
                <Row label="Model">{onu.model || '-'}</Row>
                <Row label="Line profile / service profile">{onu.profile || '-'} / {onu.serviceProfile || '-'}</Row>
                <Row label="Signal at the ONU / at the OLT"><OnuPower dbm={onu.rx} /> / <OnuPower dbm={onu.oltRx} /></Row>
                <Row label="Distance">{onu.distance === null ? '-' : `${onu.distance} m`}</Row>
                <Row label="Last came online"><Time value={onu.lastUp} /></Row>
                <Row label="Last went offline"><Time value={onu.lastDown} />{onu.downCause ? ` (${onu.downCause})` : ''}</Row>
                <Row label="Read from the OLT"><Time value={onu.seen} /></Row>
            </Panel>
            <Panel title="Description on the OLT">
                {mayChange ? (
                    <form className="toolbar" onSubmit={(e) => { e.preventDefault(); router.post(`${base}/describe`, { description }, { preserveScroll: true }); }}>
                        <Field label="Description" name="description" maxLength={64} value={description} onChange={(e) => setDescription(e.target.value)} hint="Letters, digits and simple punctuation, up to 64 characters. Letters such as ë and ç are saved as e and c." />
                        <button className="button" disabled={!description.trim() || description === onu.description}>Save on the OLT</button>
                    </form>
                ) : <p>{onu.description || '-'}</p>}
            </Panel>
            {olt.canRead ? <LiveDetails url={`${base}/live`} /> : <Panel title="Right now"><p className="hint">Live details need telnet turned on for this OLT.</p></Panel>}
        </AppLayout>
    );
}
