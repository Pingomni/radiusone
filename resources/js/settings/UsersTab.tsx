import { Link } from '@inertiajs/react';
import { DeleteButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { RoleGrid, Sheet } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Empty, Tag } from '@/components/ui';
import { useCan } from '@/lib/auth';
import type { Operator } from '@/settings/types';

export function UsersTab({ users, usersSummary, roleDescriptions }: { users: Operator[] | null; usersSummary: { enabled: number; total: number }; roleDescriptions: { role: string; description: string }[] }) {
    const can = useCan();
    if (!users) {
        return (
            <Sheet title="Users" className="settings-users-card">
                <Empty>Ask a superadmin to change users, roles, or two-factor requirements.</Empty>
            </Sheet>
        );
    }
    return (
        <>
            <Sheet
                title="Workspace users"
                description="Manage who can sign in and what they can access."
                icon="clients"
                className="settings-users-card"
                meta={<>
                    <Badge tone={usersSummary.enabled > 0 ? 'success' : 'warning'}>{usersSummary.enabled}/{usersSummary.total} enabled</Badge>
                    <Link className="button primary" href="/settings/users/create"><Icon name="plus" />New user</Link>
                </>}
            >
                <DataTable
                    rows={users}
                    rowKey={(r) => r.id}
                    exportRows
                    columns={[
                        { header: 'Name', cell: (r) => r.name },
                        { header: 'Email', cell: (r) => r.email },
                        { header: 'Username', cell: (r) => r.username },
                        { header: 'Role', cell: (r) => <Badge>{r.role}</Badge> },
                        { header: 'Status', cell: (r) => (r.enabled ? <Badge tone="success">enabled</Badge> : <Badge tone="danger">disabled</Badge>) },
                        { header: '2FA', cell: (r) => <Tag tag={r.twoFactor} /> },
                        { header: 'Last login', cell: (r) => r.lastLogin },
                        {
                            header: 'Actions',
                            noExport: true,
                            cell: (r) => (
                                <>
                                    <Link href={`/settings/users/${r.id}/edit`}>Edit</Link>{' '}
                                    {r.self ? <span className="muted-action">You</span> : can('danger.delete') && (
                                        <DeleteButton
                                            action={`/settings/users/${r.id}/delete`}
                                            message={`${r.username} can no longer sign in. Their audit history is kept.`}
                                            typeToConfirm={r.username}
                                            title={`Delete operator ${r.username}?`}
                                        />
                                    )}
                                </>
                            ),
                        },
                    ]}
                />
            </Sheet>
            <Sheet title="Role access" description="A reference for assigning the right level of workspace access." icon="lock">
                <RoleGrid roles={roleDescriptions} />
            </Sheet>
        </>
    );
}
