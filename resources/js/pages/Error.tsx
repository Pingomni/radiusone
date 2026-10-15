import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';

/** Error page: inside the workspace when signed in, standalone otherwise. */
export default function ErrorPage({ title, message, back }: { status: number; title: string; message: string; back: string }) {
    const { auth } = useShared();
    const body = (
        <div className="empty error-page">
            <h2>{title}</h2>
            <p>{message}</p>
            <div className="form-actions">
                <Link className="button primary" href={back}>Go back</Link>
                <Link className="button" href="/">Dashboard</Link>
            </div>
        </div>
    );
    if (!auth.user) {
        return <main className="login"><div className="login-shell">{body}</div></main>;
    }
    return <AppLayout title={title}>{body}</AppLayout>;
}
