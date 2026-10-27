import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { useCan } from '@/lib/auth';

/** A RadiusOne Pro page opened without a licence for it (RequirePro): what it is and where the key goes. */
export default function Pro({ name, message }: { feature: string; name: string; message: string }) {
    const can = useCan();
    return (
        <AppLayout title={name}>
            <div className="empty error-page">
                <h2>{name} is part of RadiusOne Pro</h2>
                <p>{message}</p>
                <p>Clients, services, NAS devices, RADIUS and local backups stay free in RadiusOne Community.</p>
                <div className="form-actions">
                    {can('settings.manage') && <Link className="button primary" href="/settings?tab=license">Add a licence key</Link>}
                    <a className="button" href="https://pingomni.com/radiusone/#editions" target="_blank" rel="noopener">Compare the editions</a>
                </div>
            </div>
        </AppLayout>
    );
}
