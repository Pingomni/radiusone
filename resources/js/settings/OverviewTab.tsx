import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { Sheet, SettingsRows } from '@/components/settings';
import { Badge, Tag } from '@/components/ui';
import type { Branding, Overview } from '@/settings/types';

function Destination({ title, href, icon, status }: { title: string; description?: string; href: string; icon: string; status: ReactNode }) {
    return (
        <Link className="settings-destination" href={href}>
            <span className="account-section-icon"><Icon name={icon} /></span>
            <h3>{title}</h3>
            <span className="settings-destination-status">{status}</span>
            <span className="settings-destination-action">Configure <span aria-hidden="true">→</span></span>
        </Link>
    );
}

export function OverviewTab({ overview, usersSummary, branding }: { overview: Overview; usersSummary: { enabled: number; total: number }; branding: Branding }) {
    return (
        <>
            <div className="settings-destinations">
                <Destination title="Users & access" description="Manage operators, roles, and two-factor authentication." href="/settings?tab=users" icon="clients" status={<Badge tone="success">{usersSummary.enabled} enabled</Badge>} />
                <Destination title="Notifications" description="Connect a gateway, customize templates and send customer updates." href="/settings?tab=notifications" icon="mail" status={<Badge>Email + SMS</Badge>} />
                <Destination title="Branding" description="Give your workspace and invoices a familiar identity." href="/settings?tab=branding" icon="image" status={<Badge>{branding.custom ? 'Custom logo' : 'Default logo'}</Badge>} />
                <Destination title="Backups" description="Encrypted copies to Backblaze B2, Cloudflare R2 or your own server." href="/settings?tab=backups" icon="server" status={<Badge>Remote copies</Badge>} />
                <Destination title="Data retention" description="Choose how long session and login records are kept." href="/settings?tab=retention" icon="logs" status={<Badge>History</Badge>} />
            </div>
            <Sheet title="System information" description="Environment, operator access, and backup locations for this workspace." icon="server" meta={<Tag tag={overview.engine} />}>
                <SettingsRows rows={overview.rows} />
            </Sheet>
        </>
    );
}
