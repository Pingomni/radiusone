import { Link, useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { DataTable } from '@/components/table';
import { Tabs } from '@/components/tabs';
import { Badge, Check, ConnectionState, Empty, ErrorSummary, Field, Panel, Select } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { bind, bindCheck } from '@/lib/form';

type Vendor = { label: string; summary: string; disconnect: string; demo?: boolean };
type Device = { id: number | ''; section: string; vendor: string; shortname: string; nasname: string; secret: string; description: string; enabled: boolean; coa_enabled: boolean; coa_port: string };
type Stat = { label: string; value: string; note: string; tone: string };
type NasInfo = { id: number; name: string; ip: string; vendor: string; vendorLabel: string; enabled: boolean; description: string; stats: Stat[] };
type Api = { id: number; section: string; api_enabled: boolean; api_user: string; api_password: string; apiPasswordSaved: boolean };
type SnmpValues = {
    snmp_enabled: boolean; snmp_port: string; snmp_host: string; snmp_version: string; snmp_timeout_ms: string; snmp_community: string;
    snmp_v3_user: string; snmp_v3_context: string; snmp_v3_auth_protocol: string; snmp_v3_auth_password: string; snmp_v3_priv_protocol: string; snmp_v3_priv_password: string;
};
type Snmp = {
    values: SnmpValues; authSaved: boolean; privSaved: boolean; v3Ready: boolean; versions: Record<string, string>;
    authOptions: Record<string, string>; privOptions: Record<string, string>; routerHint: string; v3Hint: string;
};
type Session = { username: string; ip: string; started: string; time: string; state: string };

function Actions({ show, label, busy }: { show: boolean; label: string; busy: boolean }) {
    return show ? <div className="span2 form-actions"><button className="primary" disabled={busy}>{label}</button></div> : null;
}

function DeviceForm({ device, vendors, canManage }: { device: Device; vendors: Record<string, Vendor>; canManage: boolean }) {
    const form = useForm<Device>(device);
    const isNew = device.id === '';
    return (
        <>
            <ErrorSummary errors={form.errors} />
            <form className="form-grid nas-form" onSubmit={(event) => { event.preventDefault(); form.post('/nas/save', { preserveScroll: true, onFinish: () => form.setData('secret', '') }); }}>
                <fieldset className="nas-vendor-choice span2">
                    <legend className="sr-only">Platform</legend>
                    {Object.entries(vendors).map(([key, v]) => (
                        <label className="nas-vendor-option" key={key}>
                            <input type="radio" name="vendor" value={key} checked={form.data.vendor === key} disabled={!canManage} onChange={() => form.setData('vendor', key)} />
                            <span><strong>{v.label}{v.demo && <> <span className="badge warning">Demo</span></>}</strong><small>{v.summary}</small></span>
                        </label>
                    ))}
                </fieldset>
                <Field label="Name" required disabled={!canManage} autoComplete="off" hint="Short label shown in lists, e.g. bras-tirana-01." {...bind(form, 'shortname')} />
                <Field label="NAS IP address" required disabled={!canManage} autoComplete="off" inputMode="decimal" hint="The address the router sends RADIUS from. The API and SNMP use it too." {...bind(form, 'nasname')} />
                <Field label="RADIUS shared secret" type="password" required={isNew} disabled={!canManage} autoComplete="new-password" hint={isNew ? 'Must match the secret configured on the router.' : 'Leave blank to keep the saved secret.'} {...bind(form, 'secret')} />
                <Field label="Description" disabled={!canManage} autoComplete="off" hint="Optional, e.g. site or rack." {...bind(form, 'description')} />
                <div className="span2"><Check label="Accept RADIUS requests from this NAS" disabled={!canManage} {...bindCheck(form, 'enabled')} /></div>
                <h3 className="span2 form-section-title">Disconnect (CoA)</h3>
                <div className="span2"><Check label="Disconnect sessions with RADIUS (CoA)" disabled={!canManage} {...bindCheck(form, 'coa_enabled')} /></div>
                <Field
                    label="CoA port"
                    type="number"
                    min={1}
                    max={65535}
                    disabled={!canManage || !form.data.coa_enabled}
                    hint={form.data.vendor === 'mikrotik'
                        ? 'UDP port of /radius incoming on the router (3799). Signed with the shared secret above; no API login needed.'
                        : 'UDP port of the dynamic-authorization server on the router (often 1700 or 3799). Signed with the shared secret above.'}
                    {...bind(form, 'coa_port')}
                />
                <Actions show={canManage} label={isNew ? 'Add NAS' : 'Save device'} busy={form.processing} />
            </form>
        </>
    );
}

function ApiForm({ values, vendor, ip, canManage }: { values: Api; vendor: string; ip: string; canManage: boolean }) {
    const form = useForm<Api>(values);
    if (vendor !== 'mikrotik') {
        return <Empty>The RouterOS API is only for MikroTik. This router disconnects with RADIUS CoA, set on the Device tab.</Empty>;
    }
    const submit = (event: React.FormEvent) => { event.preventDefault(); form.post('/nas/save', { preserveScroll: true, onFinish: () => form.setData('api_password', '') }); };
    const on = form.data.api_enabled;
    return (
        <form className="form-grid nas-form" onSubmit={submit}>
            <div className="span2"><Check label="Enable RouterOS API" disabled={!canManage} {...bindCheck(form, 'api_enabled')} /></div>
            <p className="span2 hint">{on
                ? 'Disconnects on this NAS use the RouterOS API instead of RADIUS CoA. The portal reads the session list and removes exactly the matching session.'
                : 'Off: disconnects use RADIUS CoA from the Device tab, like most RADIUS managers. Turn the API on only if CoA cannot be used on this router.'}</p>
            <div className="span2 kv nas-endpoint"><span>RouterOS API</span><strong className="mono">tls://{ip}:8729</strong></div>
            <Field label="API user" disabled={!canManage || !on} autoComplete="off" hint="Dedicated least-privilege RouterOS user (read, write, api)." {...bind(form, 'api_user')} />
            <Field label="API password" type="password" disabled={!canManage || !on} autoComplete="new-password" hint={values.apiPasswordSaved ? 'Saved. Leave blank to keep it.' : 'Required when the API is on.'} {...bind(form, 'api_password')} />
            {on && <p className="span2 hint">The portal connects to api-ssl on the NAS IP. The router certificate must be valid for that address and trusted by this server.</p>}
            <Actions show={canManage} label="Save API settings" busy={form.processing} />
        </form>
    );
}

function SnmpForm({ snmp, nasId, ip, canManage }: { snmp: Snmp | null; nasId: number; ip: string; canManage: boolean }) {
    const form = useForm<SnmpValues>(snmp?.values ?? ({} as SnmpValues));
    if (!snmp) return <p className="hint">Apply database migration 020_nas_snmp.sql to enable SNMP monitoring.</p>;
    const disabled = !canManage;
    const v3 = form.data.snmp_version === '3';
    const saved = (on: boolean) => (on ? 'Saved. Leave blank to keep it.' : 'At least 8 characters.');
    return (
        <form
            className="form-grid"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/nas/${nasId}/snmp`, { preserveScroll: true, onFinish: () => { form.setData('snmp_v3_auth_password', ''); form.setData('snmp_v3_priv_password', ''); } });
            }}
        >
            <div className="span2"><Check label="Poll this NAS over SNMP" disabled={disabled} {...bindCheck(form, 'snmp_enabled')} /></div>
            <Field label="UDP port" type="number" min={1} max={65535} disabled={disabled} {...bind(form, 'snmp_port')} />
            <Field label="Management host" disabled={disabled} autoComplete="off" placeholder={ip} hint="Optional. Blank polls the NAS IP." {...bind(form, 'snmp_host')} />
            <Select label="Version" options={snmp.versions} disabled={disabled} hint={snmp.v3Ready ? undefined : 'Apply migration 021_nas_snmp_v3.sql to enable SNMP v3.'} {...bind(form, 'snmp_version')} />
            <Field label="Timeout (ms)" type="number" min={300} max={5000} step={100} disabled={disabled} {...bind(form, 'snmp_timeout_ms')} />
            {!v3 && (
                <div className="span2 form-grid snmp-version-section">
                    <Field label="Read-only community" disabled={disabled} autoComplete="off" spellCheck={false} hint="Must match the router. Only read requests are sent." {...bind(form, 'snmp_community')} />
                    <p className="span2 hint">{snmp.routerHint}</p>
                </div>
            )}
            {v3 && snmp.v3Ready && (
                <div className="span2 form-grid snmp-version-section">
                    <Field label="Security name (user)" disabled={disabled} autoComplete="off" maxLength={32} {...bind(form, 'snmp_v3_user')} />
                    <Field label="Context (optional)" disabled={disabled} autoComplete="off" maxLength={64} hint="Usually empty." {...bind(form, 'snmp_v3_context')} />
                    <Select label="Authentication" options={snmp.authOptions} disabled={disabled} {...bind(form, 'snmp_v3_auth_protocol')} />
                    <Field label="Authentication password" type="password" disabled={disabled} autoComplete="new-password" hint={saved(snmp.authSaved)} {...bind(form, 'snmp_v3_auth_password')} />
                    <Select label="Privacy (encryption)" options={snmp.privOptions} disabled={disabled} hint="AES-128 is supported by MikroTik and Cisco; encryption requires authentication." {...bind(form, 'snmp_v3_priv_protocol')} />
                    <Field label="Privacy password" type="password" disabled={disabled} autoComplete="new-password" hint={saved(snmp.privSaved)} {...bind(form, 'snmp_v3_priv_password')} />
                    <p className="span2 hint">{snmp.v3Hint}</p>
                </div>
            )}
            <Actions show={canManage} label="Save SNMP settings" busy={form.processing} />
        </form>
    );
}

function SetupGuide({ steps }: { steps: [string, string][] | undefined }) {
    if (!steps) return <Empty>Choose a supported platform on the Device tab.</Empty>;
    return (
        <ol className="setup-guide">
            {steps.map(([title, text]) => <li key={title}><strong>{title}</strong><span>{text}</span></li>)}
        </ol>
    );
}

function Summary({ nas }: { nas: NasInfo }) {
    return (
        <section className="client-hero nas-summary" aria-label="NAS summary">
            <div className="client-identity">
                <span className="client-avatar nas-avatar" aria-hidden="true"><Icon name="server" /></span>
                <div className="client-identity-text">
                    <h2>{nas.name}</h2>
                    <div className="client-identity-meta">
                        <code>{nas.ip}</code>
                        <Badge tone="info">{nas.vendorLabel}</Badge>
                        {nas.enabled ? <Badge tone="success">accepting RADIUS</Badge> : <Badge tone="danger">RADIUS disabled</Badge>}
                    </div>
                    {nas.description && <div className="client-contacts"><span>{nas.description}</span></div>}
                </div>
            </div>
            <div className="client-stats">
                {nas.stats.map((s) => (
                    <div key={s.label} className={`client-stat${s.tone ? ` tone-${s.tone}` : ''}`}><span>{s.label}</span><strong>{s.value}</strong><small>{s.note}</small></div>
                ))}
            </div>
        </section>
    );
}

const intro = (text: string) => <p className="nas-tab-intro">{text}</p>;

export default function NasForm({ device, vendors, setupSteps, canManage, nas, api, snmp, sessions }: {
    device: Device;
    vendors: Record<string, Vendor>;
    setupSteps: Record<string, [string, string][]>;
    canManage: boolean;
    nas: NasInfo | null;
    api?: Api;
    snmp?: Snmp | null;
    sessions?: Session[];
}) {
    if (!nas) {
        return (
            <AppLayout title="New NAS Device">
                <div className="toolbar"><Link className="button" href="/nas">Back to NAS devices</Link></div>
                <Panel title="New NAS device">
                    <p className="hint">Add the router first. The RouterOS API and SNMP monitoring are set up on its page afterwards.</p>
                    <DeviceForm device={device} vendors={vendors} canManage={canManage} />
                </Panel>
                <Panel title="Router checklist">
                    {Object.entries(setupSteps).map(([vendor, steps]) => (
                        <div key={vendor}><h3>{vendors[vendor]?.label ?? vendor}{vendors[vendor]?.demo && <> <span className="badge warning">Demo</span></>}</h3><SetupGuide steps={steps} /></div>
                    ))}
                </Panel>
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Edit NAS Device">
            <div className="toolbar">
                <Link className="button" href="/nas">Back to NAS devices</Link>
                <Link className="button" href={`/nas/${nas.id}/monitor`}><Icon name="signal" />Live monitor</Link>
            </div>
            <Summary nas={nas} />
            <Tabs
                label="NAS settings"
                className="tabs nas-tabs"
                tabs={[
                    { key: 'device', title: 'Device', icon: 'server', content: <>{intro('How FreeRADIUS recognises this router. Address, name, type, secret and on/off take effect after a FreeRADIUS restart; CoA settings apply at once.')}<DeviceForm device={device} vendors={vendors} canManage={canManage} /></> },
                    { key: 'api', title: 'API', icon: 'settings', content: <>{intro('Optional RouterOS API access. When it is on, disconnects use the API instead of RADIUS CoA.')}{api && <ApiForm values={api} vendor={nas.vendor} ip={nas.ip} canManage={canManage} />}</> },
                    { key: 'snmp', title: 'SNMP', icon: 'signal', content: <>{intro('Read-only monitoring for the Monitoring page, live monitor and graphs. Changes here apply immediately; no FreeRADIUS restart is needed.')}<SnmpForm snmp={snmp ?? null} nasId={nas.id} ip={nas.ip} canManage={canManage} /></> },
                    {
                        key: 'sessions', title: 'Sessions', icon: 'clients', count: sessions?.length ?? 0,
                        content: (
                            <>
                                {intro('Accounting sessions without a stop record from this NAS.')}
                                {sessions?.length ? (
                                    <DataTable
                                        rows={sessions}
                                        columns={[
                                            { header: 'Username', cell: (s) => s.username },
                                            { header: 'IP', cell: (s) => s.ip },
                                            { header: 'Started', cell: (s) => s.started },
                                            { header: 'Time', cell: (s) => s.time },
                                            { header: 'State', cell: (s) => <ConnectionState state={s.state} /> },
                                        ]}
                                    />
                                ) : <Empty>No open accounting sessions on this NAS.</Empty>}
                            </>
                        ),
                    },
                    { key: 'setup', title: 'Router setup', icon: 'settings', content: <>{intro('What to configure on the router so it works with this portal.')}<SetupGuide steps={setupSteps[nas.vendor]} /></> },
                ]}
            />
        </AppLayout>
    );
}
