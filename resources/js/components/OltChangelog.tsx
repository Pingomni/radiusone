import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/table';
import { Field, Panel, Select, Time, State } from '@/components/ui';

export type Change = {
    id: number; at: string; actor: string; source: 'portal' | 'olt' | 'console' | string; action: string;
    sn: string | null; position: string | null; onuId: number | null; onuName: string;
    commands: string[]; detail: Record<string, unknown>;
    /** Set in the list of every OLT's changes. */
    oltId?: number; olt?: string;
};

type Kind = 'onu' | 'ports' | 'network' | 'settings' | 'config' | 'terminal';

/** What each change is called, and which group it belongs to. */
const ACTIONS: Record<string, [string, Kind]> = {
    onu_add: ['ONU added', 'onu'], onu_found: ['ONU added on the OLT', 'onu'], onu_delete: ['ONU removed', 'onu'], onu_gone: ['ONU removed on the OLT', 'onu'],
    onu_moved: ['ONU moved on the OLT', 'onu'], onu_renamed: ['ONU renamed on the OLT', 'onu'], onu_profile: ['Profile changed on the OLT', 'onu'],
    onu_disabled: ['ONU turned off on the OLT', 'onu'], onu_enabled: ['ONU turned on on the OLT', 'onu'],
    onu_reboot: ['ONU restarted', 'onu'], onu_disable: ['ONU turned off', 'onu'], onu_enable: ['ONU turned on', 'onu'], onu_describe: ['ONU renamed', 'onu'],
    onu_speed: ['Speed changed', 'onu'], onu_lan_on: ['LAN port turned on', 'onu'], onu_lan_off: ['LAN port turned off', 'onu'], onu_factory_reset: ['Factory settings restored', 'onu'],
    onu_wan: ['Internet connection set', 'onu'], onu_bridge: ['Switched to bridging', 'onu'], onu_eth_port: ['LAN port mode changed', 'onu'], onu_lan_vlan: ['LAN port VLAN changed', 'onu'],
    link_onu: ['Linked to a client', 'onu'], unlink_onu: ['Unlinked from its client', 'onu'],
    port_autofind_on: ['Autofind turned on', 'ports'], port_autofind_off: ['Autofind turned off', 'ports'], port_restart_onus: ['All ONUs on a port restarted', 'ports'],
    port_off: ['PON port turned off', 'ports'], port_on: ['PON port turned on', 'ports'], card_restart: ['Card restarted', 'ports'],
    rogue_found: ['Rogue ONU on the port', 'ports'], rogue_gone: ['Rogue ONU cleared', 'ports'],
    vlan_add: ['VLAN created', 'network'], vlan_edit: ['VLAN changed', 'network'], vlan_remove: ['VLAN deleted', 'network'],
    uplink_vlan_add: ['VLAN added to an uplink', 'network'], uplink_vlan_remove: ['VLAN taken off an uplink', 'network'],
    ntp_add: ['Time server added', 'network'], ntp_remove: ['Time server removed', 'network'], olt_traffic_table_add: ['Speed added', 'network'],
    create: ['OLT added to the portal', 'settings'], update: ['OLT settings changed', 'settings'],
    config_changed: ['Configuration changed', 'config'], olt_terminal: ['Command in the terminal', 'terminal'],
};
const KINDS: Record<Kind | '', string> = { '': 'Everything', onu: 'ONUs', ports: 'PON ports and cards', network: 'VLANs, speeds and time', settings: 'OLT settings', config: 'Configuration', terminal: 'Terminal' };
const SOURCES: Record<string, string> = { '': 'Anyone', portal: 'From the portal', olt: 'Found on the OLT', console: 'In the terminal' };

const label = (c: Change) => ACTIONS[c.action]?.[0] ?? c.action.replace(/_/g, ' ');
const kindOf = (c: Change): Kind => ACTIONS[c.action]?.[1] ?? 'settings';
const text = (v: unknown) => (typeof v === 'string' || typeof v === 'number' ? String(v) : '');

/** The one line that says what changed, from what the change kept. */
function summary(c: Change): string {
    const d = c.detail;
    switch (c.action) {
        case 'onu_describe': case 'onu_renamed': return `"${text(d.before) || '-'}" → "${text(d.after) || '-'}"`;
        case 'onu_moved': return `from ${text(d.from)} to ${c.position ?? ''}`;
        case 'onu_profile': return `${text(d.what)}: ${text(d.before)} → ${text(d.after)}`;
        case 'onu_found': case 'onu_gone': return [text(d.description), text(d.model)].filter(Boolean).join(' · ');
        case 'link_onu': return `${text(d.client)}${d.source && d.source !== 'manual' ? ` (found from the ${text(d.source) === 'mac' ? 'session MAC' : text(d.source)})` : ''}`;
        case 'unlink_onu': return text(d.client);
        case 'onu_speed': return `service port ${text(d.service_port)}`;
        case 'onu_lan_on': case 'onu_lan_off': return `LAN ${text(d.lan_port)}`;
        case 'onu_eth_port': return `LAN ${text(d.lan_port)}${d.mode ? `: ${text(d.mode)}` : ''}`;
        case 'onu_lan_vlan': return `LAN ${text(d.lan_port)}${d.vlan ? `, VLAN ${text(d.vlan)}` : ''}`;
        case 'vlan_add': case 'vlan_edit': case 'vlan_remove': return `VLAN ${text(d.vlan)}${Array.isArray(d.uplinks) && d.uplinks.length ? ` on ${d.uplinks.join(', ')}` : ''}`;
        case 'uplink_vlan_add': case 'uplink_vlan_remove': return `VLAN ${text(d.vlan)} on ${text(d.port)}`;
        case 'ntp_add': case 'ntp_remove': return text(d.server);
        case 'olt_traffic_table_add': return `traffic table ${text(d.traffic_table)}, ${Math.round(Number(d.kbps) / 1024)} Mbit/s`;
        case 'card_restart': return `${text(d.card)} in ${c.position ?? ''}`;
        case 'update': return Array.isArray(d.fields) && d.fields.length ? d.fields.map((f) => String(f).replace(/_/g, ' ')).join(', ') : '';
        case 'config_changed': return `${text(d.addedCount)} line${d.addedCount === 1 ? '' : 's'} added, ${text(d.removedCount)} removed`;
        case 'olt_terminal': return Array.isArray(d.mode) && d.mode.length ? `in ${d.mode.join(' › ')}` : '';
        default: return '';
    }
}

/** The commands sent, or the configuration lines that changed, opened on demand. */
function More({ c }: { c: Change }) {
    const added = Array.isArray(c.detail.added) ? (c.detail.added as string[]) : [];
    const removed = Array.isArray(c.detail.removed) ? (c.detail.removed as string[]) : [];
    if (c.action === 'olt_terminal' && c.commands.length === 1) return <span className="mono">{c.commands[0]}</span>;
    if (!c.commands.length && !added.length && !removed.length) return null;
    return (
        <details className="changelog-more">
            <summary>{c.commands.length ? `${c.commands.length} command${c.commands.length === 1 ? '' : 's'}` : 'What changed'}</summary>
            <pre className="mono onu-preview">
                {c.commands.join('\n')}
                {removed.map((l, i) => <span key={`r${i}`} className="changelog-removed">{`- ${l}\n`}</span>)}
                {added.map((l, i) => <span key={`a${i}`} className="changelog-added">{`+ ${l}\n`}</span>)}
            </pre>
        </details>
    );
}

/** An OLT's changelog, or one ONU's history: what was done, by whom, when, and what was found changed on the OLT. */
export function Changelog({ rows, oltId, forOnu = false, multi = false, title }: { rows: Change[]; oltId: number; forOnu?: boolean; multi?: boolean; title: string }) {
    const [q, setQ] = useState('');
    const [kind, setKind] = useState('');
    const [source, setSource] = useState('');
    const [who, setWho] = useState('');
    const [olt, setOlt] = useState('');
    const olts = useMemo(() => [...new Set(rows.map((r) => r.olt ?? '').filter(Boolean))].sort(), [rows]);
    const people = useMemo(() => [...new Set(rows.map((r) => r.actor).filter((a) => a !== ''))].sort(), [rows]);
    const shown = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return rows.filter((r) => (!kind || kindOf(r) === kind) && (!source || r.source === source) && (!who || r.actor === who) && (!olt || r.olt === olt)
            && (!needle || [label(r), summary(r), r.sn, r.onuName, r.position, r.actor, ...r.commands].some((v) => (v ?? '').toLowerCase().includes(needle))));
    }, [rows, q, kind, source, who, olt]);
    return (
        <Panel title={title}>
            <p className="hint">
                What was done from the portal, by whom, and what the portal found changed on the OLT itself: an ONU added, removed, moved or renamed there,
                and the configuration different from the day before. Kept for two years.
            </p>
            <div className="toolbar changelog-filters">
                <Field label="Search" name="changelog_q" placeholder={forOnu ? 'Action, user or command' : 'Serial, name, user or command'} value={q} onChange={(e) => setQ(e.target.value)} />
                {multi && olts.length > 1 && <Select label="OLT" name="changelog_olt" options={{ '': 'Every OLT', ...Object.fromEntries(olts.map((o) => [o, o])) }} value={olt} onChange={(e) => setOlt(e.target.value)} />}
                {!forOnu && <Select label="What" name="changelog_kind" options={KINDS} value={kind} onChange={(e) => setKind(e.target.value)} />}
                <Select label="Where from" name="changelog_source" options={SOURCES} value={source} onChange={(e) => setSource(e.target.value)} />
                {people.length > 1 && <Select label="Who" name="changelog_who" options={{ '': 'Everyone', ...Object.fromEntries(people.map((p) => [p, p])) }} value={who} onChange={(e) => setWho(e.target.value)} />}
            </div>
            <DataTable
                rows={shown}
                rowKey={(r) => r.id}
                exportRows
                perPage={50}
                emptyText={rows.length ? 'Nothing matches.' : forOnu ? 'Nothing has been done to this ONU since the portal has kept its history.' : 'Nothing yet.'}
                columns={[
                    { header: 'When', cell: (r) => <Time value={r.at} /> },
                    ...(multi ? [{ header: 'OLT', cell: (r: Change) => <Link href={`/olts/${r.oltId}?tab=history`}>{r.olt}</Link> }] : []),
                    { header: 'Who', cell: (r) => (r.source === 'olt' ? <State tone="warning">On the OLT</State> : r.source === 'console' ? <>{r.actor} <State>terminal</State></> : r.actor || '-') },
                    { header: 'What', className: 'wrap', cell: (r) => <><strong>{label(r)}</strong>{summary(r) && <div className="hint">{summary(r)}</div>}</> },
                    ...(forOnu ? [] : [{
                        header: 'ONU or port', className: 'wrap', cell: (r: Change) => (r.sn
                            ? <>{r.onuId !== null ? <Link href={`/olts/${r.oltId ?? oltId}/onus/${r.onuId}`} className="mono">{r.sn}</Link> : <span className="mono">{r.sn}</span>}{(r.onuName || text(r.detail.description)) && <div className="hint">{(r.onuName || text(r.detail.description)).replace('_descr_', ', ')}</div>}{r.position && <div className="hint mono">{r.position}</div>}</>
                            : r.position ? <span className="mono">{r.position}</span> : '-'),
                    }]),
                    { header: 'Details', className: 'wrap', cell: (r) => <More c={r} /> },
                ]}
            />
        </Panel>
    );
}
