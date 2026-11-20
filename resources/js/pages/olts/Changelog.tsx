import { Changelog, type Change } from '@/components/OltChangelog';
import AppLayout from '@/layouts/AppLayout';

/** What changed on every OLT, in one list. */
export default function OltChangelogPage({ changes }: { changes: Change[] }) {
    return (
        <AppLayout title="OLT changelog">
            <Changelog rows={changes} oltId={0} title="Every OLT" multi />
        </AppLayout>
    );
}
