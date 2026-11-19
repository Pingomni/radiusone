import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PostButton } from '@/components/confirm';
import { Badge, Empty, Panel, Time } from '@/components/ui';
import { useCan } from '@/lib/auth';
import { OnuStatus, SignalReading, type OnuRow } from '@/components/olt';

export type ClientOnuData = {
    link: { sn: string; olt: string; oltId: number; source: string; evidence: string; linkedAt: string } | null;
    onu: OnuRow | null;
    found: { mac: string; onus: { sn: string; position: string }[] } | null;
    hasOlts: boolean;
    addOn: { id: number; name: string }[];
};

const Row = ({ label, children }: { label: string; children: React.ReactNode }) => (
    <div className="kv"><span>{label}</span><strong>{children}</strong></div>
);

/** The client's ONU. The buttons are not a form because this tab sits inside the client's form. */
export default function ClientOnu({ username, data, canLink }: { username: string; data: ClientOnuData; canLink: boolean }) {
    const [sn, setSn] = useState('');
    const base = `/clients/${encodeURIComponent(username)}/onu`;
    const link = (serial: string) => router.post(base, { sn: serial }, { preserveScroll: true, onSuccess: () => setSn('') });
    const { link: current, onu, found } = data;
    const differs = current && found && !found.onus.some((o) => o.sn === current.sn);
    const can = useCan();
    const addOn = can('nas.manage') && canLink ? data.addOn : [];

    if (!data.hasOlts) return <Empty>No OLT has been added yet. Add one under <Link href="/olts">OLT</Link>.</Empty>;
    return (
        <>
            <Panel title="ONU" actions={current && canLink ? <PostButton action={`${base}/unlink`} label="Unlink" confirm={{ title: 'Unlink this ONU?', message: 'Only the link in the portal is removed. Nothing changes on the OLT.' }} /> : undefined}>
                {current ? (
                    <>
                        {onu ? (
                            <>
                                <Row label="Status">{onu.active === false ? <Badge tone="warning">Turned off</Badge> : <OnuStatus status={onu.status} />}</Row>
                                <Row label="Signal at the ONU, dBm"><SignalReading dbm={onu.rx} second={onu.oltRx === null ? undefined : `OLT ${onu.oltRx.toFixed(1).replace('-', '\u2212')}`} /></Row>
                                <Row label="OLT and port"><Link href={`/olts/${current.oltId}?q=${encodeURIComponent(current.sn)}`}>{current.olt}</Link> <span className="mono">{onu.position}</span></Row>
                                <Row label="Serial number"><span className="mono">{onu.sn}</span></Row>
                                <Row label="Description on the OLT">{onu.description || '-'}</Row>
                                <Row label="Model">{onu.model || '-'}</Row>
                                <Row label="Line profile">{onu.profile || '-'}</Row>
                                <Row label="Distance">{onu.distance === null ? '-' : `${onu.distance} m`}</Row>
                                <Row label="Last came online"><Time value={onu.lastUp} /></Row>
                                <Row label="Last went offline"><Time value={onu.lastDown} /></Row>
                                <Row label="Reason it last went offline">{onu.downCause || '-'}</Row>
                                <Row label="Read from the OLT"><Time value={onu.seen} /></Row>
                                <div className="form-actions"><Link className="button" href={`/olts/${current.oltId}/onus/${onu.id}`}>Live details and actions</Link></div>
                            </>
                        ) : (
                            <>
                                <p className="field-error" role="status">The linked ONU {current.sn} is not on {current.olt} any more. It may have been removed or replaced.</p>
                            </>
                        )}
                        <Row label="How it was linked">{current.source}{current.evidence ? `: ${current.evidence}` : ''}</Row>
                    </>
                ) : <p className="hint">No ONU yet. It is linked by itself once the client is online.</p>}
            </Panel>
            {found && (!current || differs) && (
                <Panel title={differs ? 'The session points to a different ONU' : 'Found from the session'}>
                    <p className="hint">The OLT sees this client&apos;s MAC {found.mac} behind:</p>
                    {found.onus.map((o) => (
                        <div className="kv" key={o.sn}>
                            <span className="mono">{o.sn} ({o.position})</span>
                            {canLink && <button type="button" className="button" onClick={() => link(o.sn)}>Link this ONU</button>}
                        </div>
                    ))}
                </Panel>
            )}
            {addOn.length > 0 && (!current || !onu) && (
                <Panel title="Add a new ONU for this client">
                    <p className="hint">Plug the ONU in at the customer, then look for it on the OLT. Once added, it is linked to this client.</p>
                    <div className="form-actions">
                        {addOn.map((o) => <Link key={o.id} className="button" href={`/olts/${o.id}/provision?client=${encodeURIComponent(username)}`}>Look on {o.name}</Link>)}
                    </div>
                </Panel>
            )}
            {canLink && (
                <Panel title={current ? 'Link a different ONU' : 'Link by serial number'}>
                    <label>
                        <span>ONU serial number</span>
                        <input
                            className="mono"
                            value={sn}
                            autoComplete="off"
                            spellCheck={false}
                            placeholder="HWTC1234ABCD"
                            onChange={(e) => setSn(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); link(sn); } }}
                        />
                    </label>
                    <div className="form-actions"><button type="button" className="button primary" disabled={!sn.trim()} onClick={() => link(sn)}>Link ONU</button></div>
                </Panel>
            )}
        </>
    );
}
