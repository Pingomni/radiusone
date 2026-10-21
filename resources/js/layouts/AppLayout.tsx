import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { FlashMessage } from '@/components/ui';
import { useCan, useShared } from '@/lib/auth';
import { useServerClock } from '@/lib/clock';

// Each label appears once: items are listed in group order.
const NAV: [href: string, label: string, permission: string, icon: string, group: string][] = [
    ['/', 'Dashboard', 'dashboard.view', 'dashboard', 'Operations'],
    ['/clients', 'Clients', 'clients.view', 'clients', 'Operations'],
    ['/services', 'Services', 'services.view', 'services', 'Operations'],
    ['/nas', 'NAS Devices', 'nas.view', 'server', 'Operations'],
    ['/monitoring', 'Monitoring', 'nas.view', 'activity', 'Operations'],
    ['/operations', 'Network operations', 'operations.view', 'signal', 'Operations'],
    ['/billing', 'Billing', 'billing.view', 'billing', 'Operations'],
    ['/reports', 'Reports', 'reports.view', 'reports', 'Workspace'],
    ['/logs', 'Logs', 'logs.view', 'logs', 'Workspace'],
    ['/settings', 'Settings', 'settings.manage', 'settings', 'Workspace'],
    ['/docs', 'Documentation', 'settings.manage', 'book', 'Workspace'],
];

const CONTEXTS: Record<string, string> = {
    '': 'Workspace / Overview',
    dashboard: 'Workspace / Overview',
    clients: 'Workspace / Customers',
    services: 'Workspace / Services',
    nas: 'Workspace / Network',
    monitoring: 'Workspace / Network',
    operations: 'Workspace / Network',
    billing: 'Workspace / Finance',
    reports: 'Workspace / Insights',
    logs: 'Workspace / Activity',
    settings: 'Workspace / Administration',
    docs: 'Workspace / Administration',
    profile: 'Workspace / My account',
};

export function BrandLogo({ className = 'brand-logo' }: { className?: string }) {
    const { logo } = useShared();
    const [failed, setFailed] = useState(false);
    if (logo && !failed) {
        return (
            <span className={`custom-brand ${className}`}>
                <img src={logo} alt="Portal logo" referrerPolicy="no-referrer" onError={() => setFailed(true)} />
            </span>
        );
    }
    return <span className={`mark ${className}`}><BrandMark /></span>;
}

/** The RadiusOne mark (public/brand/logo-mark.svg): an R whose leg runs out as a network link to a node. */
export function BrandMark() {
    const id = `rw-${useId().replace(/:/g, '')}`;
    const line = { stroke: '#fff', strokeWidth: 6.5, strokeLinecap: 'round', strokeLinejoin: 'round', fill: 'none' } as const;
    return (
        <svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id={id} x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse"><stop offset="0" stopColor="#2f6bf0" /><stop offset="1" stopColor="#4338ca" /></linearGradient>
            </defs>
            <rect width="64" height="64" rx="15" fill={`url(#${id})`} />
            <path d="M22 47V17h10.5a8.75 8.75 0 0 1 0 17.5H22" {...line} />
            <path d="M32 34.5L40.5 43" {...line} />
            <circle cx="44.5" cy="47" r="5.5" fill="#5eead4" />
        </svg>
    );
}

function ThemeToggle() {
    const [dark, setDark] = useState(false);
    useEffect(() => {
        const root = document.documentElement;
        setDark(root.dataset.theme ? root.dataset.theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches);
    }, []);
    return (
        <button
            type="button"
            className="theme-toggle"
            aria-pressed={dark}
            title="Switch between light and dark theme"
            onClick={() => {
                const next = dark ? 'light' : 'dark';
                document.documentElement.dataset.theme = next;
                // A cookie so the server renders the chosen theme on first paint.
                document.cookie = `frweb_theme=${next}; path=/; max-age=31536000; SameSite=Lax`;
                setDark(!dark);
            }}
        >
            <Icon name="moon" />
            <span>Dark theme</span>
        </button>
    );
}

function useMobileNav() {
    const [mobile, setMobile] = useState(() => typeof window !== 'undefined' && window.matchMedia('(max-width: 980px)').matches);
    const [open, setOpen] = useState(false);
    useEffect(() => {
        const media = window.matchMedia('(max-width: 980px)');
        const sync = () => {
            setMobile(media.matches);
            setOpen(false);
        };
        media.addEventListener('change', sync);
        return () => media.removeEventListener('change', sync);
    }, []);
    useEffect(() => {
        document.body.classList.toggle('nav-open', open);
    }, [open]);
    useEffect(() => router.on('navigate', () => setOpen(false)), []);
    return { mobile, open, setOpen };
}

/** Workspace shell: sidebar, topbar and page feedback (legacy render()). */
export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash, clock, appName, appVersion } = useShared();
    const time = useServerClock();
    const can = useCan();
    const { url } = usePage();
    const path = url.split('?')[0];
    const search = path === '/clients' ? new URLSearchParams(url.split('?')[1] ?? '').get('q') ?? '' : '';
    const { mobile, open, setOpen } = useMobileNav();
    const sidebar = useRef<HTMLElement>(null);
    const toggle = useRef<HTMLButtonElement>(null);
    const feedback = useRef<HTMLDivElement>(null);
    const [announcement, setAnnouncement] = useState('');

    // Focus full-page errors/warnings once; announce success without stealing focus.
    useEffect(() => {
        if (!flash) return;
        if (flash.type === 'danger' || flash.type === 'warning') {
            feedback.current?.querySelector<HTMLElement>('[data-feedback-focus]')?.focus();
        } else {
            setAnnouncement(flash.message);
        }
    }, [flash]);

    // Mobile navigation behaves as a modal dialog: focus trap, Escape closes, focus returns.
    useEffect(() => {
        if (!open) return;
        const controls = () => [...(sidebar.current?.querySelectorAll<HTMLElement>('a[href], button:not(:disabled)') ?? [])].filter((c) => c.getClientRects().length);
        controls()[0]?.focus();
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
                toggle.current?.focus();
            } else if (event.key === 'Tab') {
                const list = controls();
                if (!list.length) return;
                if (event.shiftKey && document.activeElement === list[0]) {
                    event.preventDefault();
                    list[list.length - 1].focus();
                } else if (!event.shiftKey && document.activeElement === list[list.length - 1]) {
                    event.preventDefault();
                    list[0].focus();
                }
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, setOpen]);

    const section = path.replace(/^\/+/, '').split('/')[0];
    let group = '';

    return (
        <>
            <Head title={`${title} - ${appName}`} />
            <a className="skip-link" href="#workspace-content">Skip to content</a>
            <aside
                className="sidebar"
                id="app-sidebar"
                aria-label="Main navigation"
                ref={sidebar}
                inert={mobile && !open ? true : undefined}
                aria-hidden={mobile && !open ? true : undefined}
                role={open ? 'dialog' : undefined}
                aria-modal={open ? true : undefined}
            >
                <button type="button" className="nav-close" onClick={() => { setOpen(false); toggle.current?.focus(); }}>Close navigation</button>
                <div className="brand">
                    <BrandLogo />
                    {/* A company name keeps the product name underneath, so people still know what this is. */}
                    <div><strong>{appName}</strong><small>{appName === 'RadiusOne' ? 'Client management' : 'RadiusOne'}</small></div>
                </div>
                <nav aria-label="Primary">
                    {NAV.filter(([, , permission]) => can(permission)).map(([href, label, , icon, navGroup]) => {
                        const active = path === href || (href !== '/' && path.startsWith(`${href}/`)) || (href === '/' && path === '/dashboard');
                        const heading = navGroup !== group ? <span className="nav-group-label">{navGroup}</span> : null;
                        group = navGroup;
                        return (
                            <Fragment key={href}>
                                {heading}
                                <Link className={active ? 'active' : ''} href={href} aria-current={active ? 'page' : undefined}>
                                    <Icon name={icon} />
                                    <span>{label}</span>
                                </Link>
                            </Fragment>
                        );
                    })}
                </nav>
                {appVersion && <div className="sidebar-version">RadiusOne v{appVersion}</div>}
            </aside>
            <button type="button" className="nav-backdrop" tabIndex={-1} aria-hidden="true" hidden={!open} onClick={() => setOpen(false)} />
            <main className="main" inert={open ? true : undefined}>
                <header className="topbar">
                    <div className="page-title">
                        <button
                            ref={toggle}
                            type="button"
                            className="mobile-nav-toggle"
                            aria-label="Open main navigation"
                            aria-controls="app-sidebar"
                            aria-expanded={open}
                            onClick={() => setOpen(!open)}
                        >
                            <Icon name="menu" />
                            <span>Menu</span>
                        </button>
                        <div>
                            <span className="workspace-eyebrow">{CONTEXTS[section] ?? 'Workspace'}</span>
                            <h1>{title}</h1>
                        </div>
                    </div>
                    {can('clients.view') && (
                        <form
                            className="topbar-search"
                            action="/clients"
                            role="search"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const q = new FormData(event.currentTarget).get('q');
                                router.get('/clients', q ? { q: String(q) } : {});
                            }}
                        >
                            <label className="sr-only" htmlFor="topbar-search-input">Search clients</label>
                            <input key={search} id="topbar-search-input" type="search" name="q" placeholder="Search user, name, phone, IP or MAC" autoComplete="off" defaultValue={search} />
                        </form>
                    )}
                    <div className="operator">
                        <span className="topbar-clock" title="All times are shown in this timezone">{time} {clock.timezone}</span>
                        <ThemeToggle />
                        <Link className="operator-id" href="/profile" title="My profile">
                            <Icon name="user" />
                            {auth.user?.username}
                        </Link>
                        <button type="button" className="logout-button" onClick={() => router.post('/logout')}>
                            <Icon name="logout" />
                            <span>Logout</span>
                        </button>
                    </div>
                </header>
                <div className="workspace-content" id="workspace-content" tabIndex={-1} ref={feedback}>
                    {flash && <FlashMessage flash={flash} />}
                    {children}
                </div>
            </main>
            <div className="sr-only" id="feedback-announcer" role="status" aria-live="polite" aria-atomic="true">{announcement}</div>
        </>
    );
}
