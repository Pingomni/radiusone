import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent, type ReactNode } from 'react';
import { FlashMessage } from '@/components/ui';
import { BrandLogo } from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';

/** The sign-in shell: the brand on the dark frame, one light card under it. */
export function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
    const { appName } = useShared();
    return (
        <>
            <Head title={`${title} - ${appName}`} />
            <main className="login">
                <div className="login-shell">
                    <div className="login-brand"><BrandLogo /><span>{appName}</span></div>
                    {children}
                    {appName !== 'RadiusOne' && <p className="login-footnote">RadiusOne</p>}
                </div>
            </main>
        </>
    );
}

/** The card form with brand, heading and flash; focuses the error when sign-in fails. */
export function AuthCard({ heading, lead, onSubmit, children }: { heading: string; lead: string; onSubmit: (event: FormEvent<HTMLFormElement>) => void; children: ReactNode }) {
    const { flash } = useShared();
    const card = useRef<HTMLFormElement>(null);
    useEffect(() => {
        if (flash) card.current?.querySelector<HTMLElement>('[data-feedback-focus]')?.focus();
    }, [flash]);
    return (
        <form method="post" className="login-card" onSubmit={onSubmit} ref={card} noValidate={false}>
            <div className="login-card-head">
                <h1>{heading}</h1>
                {lead && <p>{lead}</p>}
            </div>
            {flash && <FlashMessage flash={flash} />}
            {children}
        </form>
    );
}

export function CancelSignIn() {
    return <button type="button" className="wide" onClick={() => router.post('/logout')}>Cancel sign in</button>;
}
