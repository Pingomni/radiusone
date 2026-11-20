import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { Sheet, SettingsRows } from '@/components/settings';
import { Tag, State } from '@/components/ui';
import { UpdateSheet } from '@/settings/UpdateSheet';
import type { Branding, Overview, SoftwareUpdate } from '@/settings/types';

function Destination({ title, href, icon, status }: { title: string; description?: string; href: string; icon: string; status: ReactNode }) {
    return (
        <Link className="settings-destination" href={href}>
            <span className="account-section-icon"><Icon name={icon} /></span>
            <h3>{title}</h3>
            <span className="settings-destination-status">{status}</span>
            <span className="settings-destination-action">Configure</span>
        </Link>
    );
}

export function OverviewTab({ overview, usersSummary, branding, update }: { overview: Overview; usersSummary: { enabled: number; total: number }; branding: Branding; update: SoftwareUpdate | null }) {
    return (
        <>
            <div className="settings-destinations">
                <Destination title="Users & access" description="Manage operators, roles, and two-factor authentication." href="/settings?tab=users" icon="clients" status={<State tone="success">{usersSummary.enabled} enabled</State>} />
                <Destination title="Notifications" description="Connect a gateway, customize templates and send customer updates." href="/settings?tab=notifications" icon="mail" status={<State>Email + SMS</State>} />
                <Destination title="Branding" description="Give your workspace and invoices a familiar identity." href="/settings?tab=branding" icon="image" status={<State>{branding.custom ? 'Custom logo' : 'Default logo'}</State>} />
                <Destination title="Backups" description="Encrypted copies to Backblaze B2, Cloudflare R2 or your own server." href="/settings?tab=backups" icon="server" status={<State>Remote copies</State>} />
                <Destination title="Data retention" description="Choose how long session and login records are kept." href="/settings?tab=retention" icon="logs" status={<State>History</State>} />
            </div>
            <Sheet title="System information" description="Environment, operator access, and backup locations for this workspace." icon="server" meta={<Tag tag={overview.engine} />}>
                <SettingsRows rows={overview.rows} />
            </Sheet>
            {update && <UpdateSheet u={update} />}
        </>
    );
}
