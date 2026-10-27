import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Guide from '@/components/guide';
import AppLayout from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';
import guide from '../../../../docs/USER-GUIDE.md?raw';

/*
 * Administrator documentation: docs/USER-GUIDE.md, bundled at build time (so it matches this version and
 * works without internet). The same guide is published on pingomni.com/docs/radiusone/. Examples use
 * documentation placeholders only (192.0.2.x, example.com, 123456789), never this install's hosts,
 * addresses or credentials.
 */

const REPO_DOCS = 'https://github.com/n-geraldo/radiusone/blob/main/docs/';

const ICONS: Record<string, string> = {
    'getting-started': 'book',
    dashboard: 'dashboard',
    clients: 'clients',
    'services-and-ip-pools': 'services',
    'nas-devices': 'server',
    monitoring: 'activity',
    'network-operations': 'signal',
    billing: 'billing',
    'reports-and-logs': 'reports',
    'your-profile': 'user',
    'settings-operators': 'lock',
    'settings-email-and-sms-gateways': 'mail',
    'settings-customer-messages': 'message',
    'settings-admin-alerts-and-daily-report': 'shield',
    'settings-branding': 'image',
    'settings-backups': 'refresh',
    'settings-data-retention': 'logs',
    'server-operations': 'settings',
    troubleshooting: 'search',
};

const appLink = (href: string, children: ReactNode, key: string) => <Link key={key} href={href}>{children}</Link>;

export default function Docs() {
    const { appVersion } = useShared();
    return (
        <AppLayout title="Documentation">
            <Guide
                markdown={guide}
                title="RadiusOne user guide"
                product="RadiusOne"
                version={appVersion}
                icons={ICONS}
                docsBase={REPO_DOCS}
                appLink={appLink}
                searchPlaceholder="e.g. CoA, backups, Telegram"
            />
        </AppLayout>
    );
}
