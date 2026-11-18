import { Link, useForm } from '@inertiajs/react';
import { DataTable } from '@/components/table';
import { ErrorSummary, Field, Panel, Select } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { bind } from '@/lib/form';

type Profile = { id: number; name: string; bound: number };
type Found = { frame: number; slot: number; port: number; sn: string; model: string; software: string; found: string };
type Options = {
    found: Found[]; lineProfiles: Profile[]; serviceProfiles: Profile[]; wanProfiles: Profile[]; routeProfiles: Profile[];
    vlans: { id: number; ports: number }[]; trafficTables: { id: number; cir: string; pir: string }[];
    defaults: Record<string, string>;
};
type Values = { sn: string; mode: string; line_profile: string; service_profile: string; vlan: string; gem: string; traffic_up: string; traffic_down: string; wan_profile: string; route_profile: string; description: string; client: string };

const profileOptions = (rows: Profile[]) => rows.map((p) => ({ value: String(p.id), label: `${p.name} (${p.bound} in use)` }));
const speed = (kbps: string) => (kbps === 'off' ? 'no limit' : Number(kbps) >= 1000 ? `${Math.round(Number(kbps) / 100) / 10} Mbit/s` : `${kbps} kbit/s`);

export default function Provision({ olt, options, error, client }: { olt: { id: number; name: string; canChange: boolean }; options: Options | null; error: string; client: { username: string; description: string } | null }) {
    const d = options?.defaults ?? {};
    const form = useForm<Values>({
        sn: options?.found.length === 1 ? options.found[0].sn : '',
        mode: d.mode || 'router', line_profile: d.line_profile ?? '', service_profile: d.service_profile ?? '', vlan: d.vlan ?? '', gem: d.gem || '1',
        traffic_up: d.traffic_up ?? '', traffic_down: d.traffic_down ?? '', wan_profile: d.wan_profile ?? '', route_profile: d.route_profile ?? '',
        description: client?.description ?? '', client: client?.username ?? '',
    });
    const path = `/olts/${olt.id}/provision`;
    const tables = (options?.trafficTables ?? []).map((t) => ({ value: String(t.id), label: `${t.id}: ${speed(t.pir)}` }));
    const chosen = options?.found.find((o) => o.sn === form.data.sn);
    return (
        <AppLayout title={`New ONUs on ${olt.name}`}>
            <div className="toolbar">
                <Link className="button" href={`/olts/${olt.id}`}>{olt.name}</Link>
                <Link className="button" href={path + (client ? `?client=${encodeURIComponent(client.username)}` : '')}>Look again</Link>
            </div>
            {error && <p className="flash danger" role="alert">{error}</p>}
            {options && (
                <>
                    <Panel title="Plugged in, not yet added">
                        <p className="hint">Read from the OLT just now. An ONU shows here a few seconds after it is connected to the fibre and powered on.</p>
                        <DataTable
                            rows={options.found}
                            rowKey={(o) => o.sn}
                            emptyText="The OLT sees no new ONU. Check that the ONU is powered, the fibre is connected, and that it is not already added."
                            columns={[
                                { header: 'Port', cell: (o) => <span className="mono">{o.frame}/{o.slot}/{o.port}</span> },
                                { header: 'Serial', cell: (o) => <span className="mono">{o.sn}</span> },
                                { header: 'Model', cell: (o) => o.model || '-' },
                                { header: 'Software', cell: (o) => o.software || '-' },
                                { header: 'Found', cell: (o) => o.found || '-' },
                                ...(olt.canChange ? [{ header: '', cell: (o: Found) => (form.data.sn === o.sn ? <strong>Chosen</strong> : <button type="button" className="button" onClick={() => form.setData('sn', o.sn)}>Choose</button>) }] : []),
                            ]}
                        />
                    </Panel>
                    {!olt.canChange && <p className="hint">Changes are turned off for this OLT, so ONUs cannot be added from here. Turn on <Link href={`/olts/${olt.id}/edit`}>Allow changes from the portal</Link> first.</p>}
                    {olt.canChange && options.found.length > 0 && (
                        <form onSubmit={(e) => { e.preventDefault(); form.post(path, { preserveScroll: true }); }}>
                            <ErrorSummary errors={form.errors} />
                            <Panel title={chosen ? `Add ${chosen.sn} on ${chosen.frame}/${chosen.slot}/${chosen.port}` : 'Choose an ONU above'}>
                                <p className="hint">Filled in with what the last ONU was added with, or what most ONUs on this OLT use. The speed limit of the customer&apos;s plan stays with RADIUS: the traffic tables here are only the OLT&apos;s own ceiling.</p>
                                <div className="form-grid">
                                    <Field label="Description on the OLT" required maxLength={64} hint="For example the customer's name. Letters, digits and simple punctuation." {...bind(form, 'description')} />
                                    <Field label="Client username (optional)" autoComplete="off" hint="The ONU is linked to this client once added." {...bind(form, 'client')} />
                                    <Select label="Mode" options={{ router: 'Router: the ONU gets its address by DHCP and shares it', bridge: 'Bridge: LAN port 1 is passed straight through' }} {...bind(form, 'mode')} />
                                    <Select label="VLAN" placeholder="Choose" options={options.vlans.map((v) => ({ value: String(v.id), label: `${v.id} (${v.ports} service ports)` }))} {...bind(form, 'vlan')} />
                                    <Select label="Line profile" placeholder="Choose" options={profileOptions(options.lineProfiles)} {...bind(form, 'line_profile')} />
                                    <Select label="Service profile" placeholder="Choose" hint="Match the ONU's model, or its number of LAN ports." options={profileOptions(options.serviceProfiles)} {...bind(form, 'service_profile')} />
                                    <Select label="Upload traffic table" placeholder="Choose" options={tables} {...bind(form, 'traffic_up')} />
                                    <Select label="Download traffic table" placeholder="Choose" options={tables} {...bind(form, 'traffic_down')} />
                                    <Field label="GEM port" type="number" min={0} max={1023} hint="The GEM port of the line profile that carries internet." {...bind(form, 'gem')} />
                                    {form.data.mode === 'router' && (
                                        <>
                                            <Select label="WAN profile" placeholder="None" options={profileOptions(options.wanProfiles)} {...bind(form, 'wan_profile')} />
                                            <Select label="Route policy profile" placeholder="None" options={profileOptions(options.routeProfiles)} {...bind(form, 'route_profile')} />
                                        </>
                                    )}
                                </div>
                            </Panel>
                            <div className="form-actions"><button className="primary" disabled={form.processing || !chosen}>{form.processing ? 'Adding on the OLT…' : 'Add this ONU'}</button></div>
                        </form>
                    )}
                </>
            )}
        </AppLayout>
    );
}
