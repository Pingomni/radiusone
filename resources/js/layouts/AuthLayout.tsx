import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent, type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { FlashMessage } from '@/components/ui';
import { BrandLogo } from '@/layouts/AppLayout';
import { useShared } from '@/lib/auth';

/** Two-column sign-in shell: intro on the left, card form on the right. */
export function AuthLayout({ title, intro, children }: { title: string; intro: ReactNode; children: ReactNode }) {
    return (
        <>
            <Head title={title} />
            <main className="login">
                <div className="login-shell">
                    <section className="login-intro">
                        <div className="login-brand"><BrandLogo /><strong>RadiusWeb</strong></div>
                        {intro}
                    </section>
                    {children}
                </div>
            </main>
        </>
    );
}

/** The card form with heading and flash; focuses the error when sign-in fails. */
export function AuthCard({ icon, eyebrow, heading, lead, onSubmit, children }: { icon: string; eyebrow?: string; heading: string; lead: string; onSubmit: (event: FormEvent<HTMLFormElement>) => void; children: ReactNode }) {
    const { flash } = useShared();
    const card = useRef<HTMLFormElement>(null);
    useEffect(() => {
        if (flash) card.current?.querySelector<HTMLElement>('[data-feedback-focus]')?.focus();
    }, [flash]);
    return (
        <form method="post" className="login-card" onSubmit={onSubmit} ref={card} noValidate={false}>
            <div className="login-card-head">
                <span className="login-head-icon"><Icon name={icon} /></span>
                <div>
                    {eyebrow && <span className="workspace-eyebrow">{eyebrow}</span>}
                    <h1>{heading}</h1>
                    <p>{lead}</p>
                </div>
            </div>
            {flash && <FlashMessage flash={flash} />}
            {children}
        </form>
    );
}

export function CancelSignIn() {
    return <button type="button" className="wide" onClick={() => router.post('/logout')}>Cancel sign in</button>;
}
