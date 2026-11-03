import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PostButton } from '@/components/confirm';
import { Empty, Panel, Time } from '@/components/ui';
import { OnuStatus, OnuPower, type OnuRow } from '@/components/olt';

export type ClientOnuData = {
    link: { sn: string; olt: string; oltId: number; source: string; evidence: string; linkedAt: string } | null;
    onu: OnuRow | null;
    found: { mac: string; onus: { sn: string; position: string }[] } | null;
    hasOlts: boolean;
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

    if (!data.hasOlts) return <Empty>No OLT has been added yet. Add one under <Link href="/olts">OLT</Link>.</Empty>;
    return (
        <>
            <Panel title="ONU" actions={current && canLink ? <PostButton action={`${base}/unlink`} label="Unlink" confirm={{ title: 'Unlink this ONU?', message: 'Only the link in the portal is removed. Nothing changes on the OLT.' }} /> : undefined}>
                {current ? (
                    <>
                        {onu ? (
                            <>
                                <Row label="Status"><OnuStatus status={onu.status} /></Row>
                                <Row label="Signal at the ONU / at the OLT"><OnuPower dbm={onu.rx} /> / <OnuPower dbm={onu.oltRx} /></Row>
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
                            </>
                        ) : (
                            <>
                                <p className="field-error" role="status">The linked ONU {current.sn} is not on {current.olt} any more. It may have been removed or replaced.</p>
                            </>
                        )}
                        <Row label="How it was linked">{current.source}{current.evidence ? `: ${current.evidence}` : ''}</Row>
                    </>
                ) : <p className="hint">This client has no ONU yet. When the client is online, the portal looks for the session&apos;s MAC behind an ONU and links it if it finds exactly one.</p>}
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
