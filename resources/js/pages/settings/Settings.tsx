import { SettingsLinkNav } from '@/components/settings';
import AppLayout from '@/layouts/AppLayout';
import { BackupsTab } from '@/settings/BackupsTab';
import { BrandingTab } from '@/settings/BrandingTab';
import { DocumentsTab } from '@/settings/DocumentsTab';
import { NotificationsTab } from '@/settings/NotificationsTab';
import { OverviewTab } from '@/settings/OverviewTab';
import { RegionalTab } from '@/settings/RegionalTab';
import { RetentionTab } from '@/settings/RetentionTab';
import { UsersTab } from '@/settings/UsersTab';
import type { Backups, Branding, Documents, Notifications, Operator, Overview, Regional, Retention, SoftwareUpdate } from '@/settings/types';

export default function Settings({ tab, overview, update, users, usersSummary, roleDescriptions, branding, notifications, documents, regional, retention, backups }: {
    tab: 'overview' | 'users' | 'notifications' | 'branding' | 'documents' | 'regional' | 'retention' | 'backups';
    overview: Overview;
    update: SoftwareUpdate | null;
    users: Operator[] | null;
    usersSummary: { enabled: number; total: number };
    roleDescriptions: { role: string; description: string }[];
    branding: Branding;
    notifications: Notifications | null;
    documents: Documents | null;
    regional: Regional | null;
    retention: Retention | null;
    backups: Backups | null;
}) {
    return (
        <AppLayout title="Settings">
            <div className="settings-console">
                <SettingsLinkNav active={tab} />
                <div className="settings-menu-body">
                    {tab === 'overview' && <OverviewTab overview={overview} usersSummary={usersSummary} branding={branding} update={update} />}
                    {tab === 'users' && <UsersTab users={users} usersSummary={usersSummary} roleDescriptions={roleDescriptions} />}
                    {tab === 'notifications' && notifications && <NotificationsTab n={notifications} />}
                    {tab === 'branding' && <BrandingTab branding={branding} />}
                    {tab === 'documents' && documents && <DocumentsTab d={documents} />}
                    {tab === 'regional' && regional && <RegionalTab r={regional} />}
                    {tab === 'retention' && retention && <RetentionTab r={retention} />}
                    {tab === 'backups' && backups && <BackupsTab b={backups} />}
                </div>
            </div>
        </AppLayout>
    );
}
