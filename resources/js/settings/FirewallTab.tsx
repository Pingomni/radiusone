import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DeleteButton, PostButton } from '@/components/confirm';
import { Sheet } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Field, FormActions, Select, TextArea } from '@/components/ui';
import type { Firewall, FirewallRule, FirewallStatus } from '@/settings/types';

const PAGE = { preserveScroll: true };

const ACTIONS = { accept: 'Accept', drop: 'Drop (silently)', reject: 'Reject (tell the sender)' };
const PROTOCOLS = { tcp: 'TCP', udp: 'UDP', 'tcp+udp': 'TCP and UDP', icmp: 'ICMP', any: 'Any' };
const DURATIONS = { 0: 'Permanently', 60: '1 hour', 1440: '1 day', 10080: '1 week' };

/** The rule form, for a new rule or an existing one. */
function RuleForm({ rule, onDone }: { rule: FirewallRule | null; onDone: () => void }) {
    const form = useForm({
        action: rule?.action ?? 'accept',
        protocol: rule?.protocol ?? 'tcp',
        source: rule?.source ?? '',
        ports: rule?.ports ?? '',
        comment: rule?.comment ?? '',
        minutes: '0',
    });
    const portless = form.data.protocol === 'icmp' || form.data.protocol === 'any';
    return (
        <form
            className="form-grid panel-text"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(rule ? `/settings/firewall/rules/${rule.id}` : '/settings/firewall/rules', { ...PAGE, onSuccess: onDone });
            }}
        >
            <Select label="Action" name="action" options={ACTIONS} value={form.data.action} onChange={(e) => form.setData('action', e.target.value as FirewallRule['action'])} />
            <Select label="Protocol" name="protocol" options={PROTOCOLS} value={form.data.protocol} onChange={(e) => form.setData('protocol', e.target.value as FirewallRule['protocol'])} />
            <Field
                label="Source"
                name="source"
                spellCheck={false}
                placeholder="Empty for anyone"
                hint={'An address, a network such as 198.51.100.0/24, or "routers" for the routers under NAS.'}
                value={form.data.source}
                onChange={(e) => form.setData('source', e.target.value)}
            />
            {!portless && (
                <Field
                    label="Ports"
                    name="ports"
                    spellCheck={false}
                    placeholder="Empty for all"
                    hint="For example 3306, or 80,443, or 1000-2000."
                    value={form.data.ports}
                    onChange={(e) => form.setData('ports', e.target.value)}
                />
            )}
            <Field label="Comment" name="comment" value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} />
            {!rule && <Select label="For" name="minutes" options={DURATIONS} value={form.data.minutes} onChange={(e) => form.setData('minutes', e.target.value)} />}
            <FormActions>
                <button type="submit" disabled={form.processing}>{rule ? 'Save rule' : 'Add rule'}</button>
                <button type="button" className="button secondary" onClick={onDone}>Cancel</button>
            </FormActions>
        </form>
    );
}

/** Follows a change the helper is applying, and confirms it from the browser of whoever asked for it. */
function useApplying(f: Firewall): FirewallStatus | null {
    const [status, setStatus] = useState<FirewallStatus | null>(f.busy ? f.status : null);
    const confirmed = useRef('');
    useEffect(() => {
        if (!f.busy) {
            setStatus(null);
            return;
        }
        let stop = false;
        const timer = window.setInterval(async () => {
            let next: FirewallStatus;
            try {
                const response = await fetch('/settings/firewall/status', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                next = (await response.json()) as FirewallStatus;
            } catch {
                return; // Unreachable for now: if the rules cut this browser off, they come back within a minute.
            }
            if (stop) return;
            setStatus(next);
            if (next.state === 'confirming' && next.by === f.username && next.id && confirmed.current !== next.id) {
                confirmed.current = next.id;
                router.post('/settings/firewall/confirm', { id: next.id }, { ...PAGE, preserveState: true });
            } else if (!next.busy) {
                router.reload({ only: ['firewall', 'flash'] });
            }
        }, 1000);
        return () => {
            stop = true;
            window.clearInterval(timer);
        };
    }, [f.busy, f.username]);
    return status;
}

export function FirewallTab({ f }: { f: Firewall }) {
    const management = useForm({ networks: f.config.management_networks.join('\n') });
    const [editing, setEditing] = useState<FirewallRule | 'new' | null>(null);
    const applying = useApplying(f);
    const blocked = f.busy || !f.ready;
    const failed = f.status.state === 'rolled_back' || f.status.state === 'failed';
    const state = applying ? <Badge tone="info">applying…</Badge> : f.on ? <Badge tone="success">on</Badge> : <Badge>off</Badge>;
    return (
        <>
            <Sheet title="Firewall" icon="shield" meta={<>{state} {f.pending && <Badge tone="warning">changes not applied</Badge>}</>}>
                {!f.ready && <div className="flash warning panel-text">Not set up on this server yet. Run <code>sudo radiusone-update</code> once.</div>}
                {f.warnings.map((warning) => <div key={warning} className="flash warning panel-text">{warning}</div>)}
                {(applying?.message || f.status.message) && (
                    <p className={`panel-text ${failed && !applying ? 'danger-text' : ''}`.trim()}>{applying?.message ?? f.status.message}</p>
                )}
                <p className="hint panel-text">
                    When it is on, this server drops every incoming connection the rules below do not allow. After you turn it on, this page must
                    reach the server again within a minute, or the previous rules come back by themselves.
                </p>
                <div className="form-actions panel-text">
                    {f.on ? (
                        <>
                            <PostButton
                                action="/settings/firewall/apply"
                                label="Apply changes"
                                className="button"
                                disabled={blocked || !f.pending}
                                confirm={{ title: 'Apply these rules?', message: 'They go in force now. If this page cannot reach the server within a minute, the previous rules come back.', confirmLabel: 'Apply' }}
                            />
                            <PostButton
                                action="/settings/firewall/disable"
                                label="Turn off"
                                className="button secondary"
                                disabled={blocked}
                                confirm={{ title: 'Turn the firewall off?', message: 'Every port on this server becomes reachable, unless another firewall filters it. Your rules are kept, so you can turn it back on.', confirmLabel: 'Turn off' }}
                            />
                        </>
                    ) : (
                        <PostButton
                            action="/settings/firewall/enable"
                            label="Turn on"
                            className="button"
                            disabled={blocked}
                            confirm={{ title: 'Turn the firewall on?', message: 'The rules below go in force now. If this page cannot reach the server within a minute, the firewall turns off again by itself.', confirmLabel: 'Turn on' }}
                        />
                    )}
                </div>
                {f.active && <p className="hint panel-text">Last changed {f.active.at} by {f.active.by}.</p>}
            </Sheet>

            <Sheet title="Management networks" icon="lock">
                <form
                    className="form-grid"
                    onSubmit={(event) => {
                        event.preventDefault();
                        management.post('/settings/firewall/management', PAGE);
                    }}
                >
                    <TextArea
                        label="Addresses and networks"
                        name="networks"
                        rows={4}
                        spellCheck={false}
                        hint={`One per line. SSH (port ${f.sshPorts.join(', ')}) and the portal are always open to them, whatever the rules say. You are at ${f.clientIp}. If your address changes, you may lose SSH: use a fixed address or a VPN network.`}
                        value={management.data.networks}
                        onChange={(e) => management.setData('networks', e.target.value)}
                    />
                    <FormActions><button type="submit" disabled={management.processing}>Save</button></FormActions>
                </form>
            </Sheet>

            <Sheet
                title="Rules"
                icon="logs"
                meta={
                    <>
                        <PostButton
                            action="/settings/firewall/template"
                            label="Restore the template"
                            className="link"
                            confirm={{ title: 'Restore the template?', message: 'Your rules are replaced with the template: the portal from anywhere and RADIUS from your routers.', confirmLabel: 'Restore' }}
                        />{' '}
                        <button type="button" onClick={() => setEditing('new')}>New rule</button>
                    </>
                }
            >
                <p className="hint panel-text">
                    Checked in order, after the management networks. Anything no rule accepts is dropped.
                    {f.routers.length > 0 ? ` "routers" means ${f.routers.length} router address${f.routers.length === 1 ? '' : 'es'} under NAS, and follows them automatically.` : ''}
                </p>
                {editing && <RuleForm key={editing === 'new' ? 'new' : editing.id} rule={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
                <DataTable
                    rows={f.rules}
                    rowKey={(rule) => rule.id}
                    emptyText="No rules: only the management networks get in."
                    columns={[
                        { header: '#', cell: (_, index) => index + 1 },
                        { header: 'Action', cell: (rule) => <Badge tone={rule.action === 'accept' ? 'success' : 'danger'}>{rule.action}</Badge> },
                        { header: 'Protocol', cell: (rule) => PROTOCOLS[rule.protocol] },
                        { header: 'Source', cell: (rule) => <span className="mono">{rule.source || 'anyone'}</span> },
                        { header: 'Ports', cell: (rule) => <span className="mono">{rule.ports || (rule.protocol === 'icmp' || rule.protocol === 'any' ? '-' : 'all')}</span> },
                        { header: 'Comment', cell: (rule) => rule.comment || '-' },
                        { header: 'State', cell: (rule) => (rule.expired ? <Badge>expired</Badge> : rule.enabled ? <Badge tone="success">on</Badge> : <Badge>off</Badge>) },
                        {
                            header: 'Actions',
                            noExport: true,
                            cell: (rule) => (
                                <>
                                    <PostButton action={`/settings/firewall/rules/${rule.id}/move`} data={{ by: -1 }} label="↑" className="link" title="Move up" />{' '}
                                    <PostButton action={`/settings/firewall/rules/${rule.id}/move`} data={{ by: 1 }} label="↓" className="link" title="Move down" />{' '}
                                    <button type="button" className="link" onClick={() => setEditing(rule)}>Edit</button>{' '}
                                    <PostButton action={`/settings/firewall/rules/${rule.id}/toggle`} label={rule.enabled ? 'Turn off' : 'Turn on'} className="link" />{' '}
                                    <DeleteButton action={`/settings/firewall/rules/${rule.id}/delete`} message="The rule stops applying at the next Apply." />
                                </>
                            ),
                        },
                    ]}
                />
            </Sheet>

            <Sheet title="The nftables rules" icon="server">
                <details className="panel-text">
                    <summary>What these settings look like on the server</summary>
                    <pre className="mono">{f.preview}</pre>
                </details>
            </Sheet>
        </>
    );
}
