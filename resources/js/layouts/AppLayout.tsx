import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { DOCS_URL } from '@/lib/docs';
import { FlashMessage } from '@/components/ui';
import { useCan, useShared } from '@/lib/auth';
import { useServerClock } from '@/lib/clock';
import type { ProFeature } from '@/lib/types';

type NavCount = 'clients' | 'weakOnus';

// Each label appears once: items are listed in group order. The last field names a count shown beside the item.
const NAV: [href: string, label: string, permission: string, icon: string, group: string, count?: NavCount][] = [
    ['/', 'Overview', 'dashboard.view', 'dashboard', 'Operations'],
    ['/clients', 'Clients', 'clients.view', 'clients', 'Operations', 'clients'],
    ['/services', 'Services', 'services.view', 'services', 'Operations'],
    ['/nas', 'NAS devices', 'nas.view', 'server', 'Network'],
    ['/onus', 'ONUs', 'nas.view', 'onu', 'Network', 'weakOnus'],
    ['/olts', 'OLTs', 'nas.view', 'fiber', 'Network'],
    ['/monitoring', 'Monitoring', 'nas.view', 'activity', 'Network'],
    ['/operations', 'Network operations', 'operations.view', 'signal', 'Network'],
    ['/billing', 'Billing', 'billing.view', 'billing', 'Finance'],
    ['/contracts', 'Contracts', 'billing.view', 'book', 'Finance'],
    ['/payments', 'Online payments', 'billing.view', 'activity', 'Finance'],
    ['/reports', 'Reports', 'reports.view', 'reports', 'Workspace'],
    ['/logs', 'Logs', 'logs.view', 'logs', 'Workspace'],
    ['/settings', 'Settings', 'settings.manage', 'settings', 'Workspace'],
];

// Menu items that are RadiusOne Pro features: marked "Pro" while this server has no licence for them.
const PRO_NAV: Record<string, ProFeature> = { '/billing': 'billing', '/contracts': 'contracts', '/payments': 'contracts' };

/** Where a page sits in the menu, for the breadcrumb: its group and its menu item. */
function placeOf(path: string): { group: string; label: string; href: string } | null {
    const section = path.replace(/^\/+/, '').split('/')[0];
    if (section === '' || section === 'dashboard') return { group: 'Operations', label: 'Overview', href: '/' };
    if (section === 'profile') return { group: 'Workspace', label: 'My account', href: '/profile' };
    const item = NAV.find(([href]) => href === `/${section}`);
    return item ? { group: item[4], label: item[1], href: item[0] } : null;
}

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

/** The RadiusOne mark, the Key, from the PingOmni family (Monitor's Pulse, DNSOne's Tree): a ring open where the line
 * leaves, one stroke, the teal ping at (57,32). The key lets the subscriber in. Ink follows the text colour. */
export function BrandMark() {
    return (
        <svg className="po-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
            <path d="M47.98 40.77A20 20 0 1 1 47.98 23.23M29 32H57M38 32V39" fill="none" stroke="currentColor" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" />
            <circle cx="24" cy="32" r="5" fill="none" stroke="currentColor" strokeWidth="5" />
            <circle className="po-mark-dot" cx="57" cy="32" r="5.5" />
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
            className="theme-toggle icon-button"
            aria-pressed={dark}
            title="Switch between light and dark theme"
            onClick={() => {
                const next = dark ? 'light' : 'dark';
                document.documentElement.dataset.theme = next;
                // A cookie so the server renders the chosen theme on first paint.
                document.cookie = `radiusone_theme=${next}; path=/; max-age=31536000; SameSite=Lax`;
                document.cookie = 'frweb_theme=; path=/; max-age=0; SameSite=Lax';
                setDark(!dark);
            }}
        >
            <Icon name={dark ? 'sun' : 'moon'} />
            <span className="sr-only">Dark theme</span>
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

type SearchResults = { q: string; clients: { username: string; name: string; ip: string; online: boolean; href: string }[]; clientTotal: number; invoices: { number: string; username: string; href: string }[]; onus?: { name: string; sn: string; where: string; online: boolean; href: string }[]; devices?: { name: string; sub: string; href: string }[] };

/** The top bar's search: Ctrl K (⌘K) jumps to it; typing lists clients and invoices; Enter opens the client list. */
function TopSearch({ initial }: { initial: string }) {
    const [value, setValue] = useState(initial);
    const [results, setResults] = useState<SearchResults | null>(null);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const input = useRef<HTMLInputElement>(null);
    const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                input.current?.focus();
                input.current?.select();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        const q = value.trim();
        if (q.length < 2) {
            setResults(null);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
                .then((r) => (r.ok ? r.json() : null))
                .then((data: SearchResults | null) => { if (data) { setResults(data); setActive(-1); } })
                .catch(() => undefined);
        }, 180);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [value]);

    const items = results ? [
        ...results.clients.map((c) => ({ key: `c-${c.username}`, href: c.href, title: c.name || c.username, sub: [c.username, c.ip].filter(Boolean).join('  '), online: c.online, kind: 'client' as const })),
        ...(results.onus ?? []).map((o) => ({ key: `o-${o.href}`, href: o.href, title: o.name, sub: `${o.sn}  ${o.where}`, online: o.online, kind: 'onu' as const })),
        ...(results.devices ?? []).map((d) => ({ key: `d-${d.href}`, href: d.href, title: d.name, sub: d.sub, online: false, kind: 'device' as const })),
        ...results.invoices.map((i) => ({ key: `i-${i.number}`, href: i.href, title: i.number, sub: i.username, online: false, kind: 'invoice' as const })),
    ] : [];
    const showAll = !!results && results.clientTotal > results.clients.length;
    const listOpen = open && !!results;
    const go = (href: string) => { setOpen(false); router.visit(href); };

    return (
        <form
            className="topbar-search"
            action="/clients"
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                if (active >= 0 && items[active]) return go(items[active].href);
                const q = value.trim();
                setOpen(false);
                router.get('/clients', q ? { q } : {});
            }}
        >
            <Icon name="search" />
            <label className="sr-only" htmlFor="topbar-search-input">Find a client, IP, MAC or invoice</label>
            <input
                ref={input}
                id="topbar-search-input"
                type="search"
                name="q"
                placeholder="Find a client, IP, MAC or invoice"
                autoComplete="off"
                role="combobox"
                aria-expanded={listOpen}
                aria-controls="topbar-search-results"
                aria-autocomplete="list"
                aria-activedescendant={listOpen && active >= 0 ? `search-option-${active}` : undefined}
                value={value}
                onChange={(e) => { setValue(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((i) => Math.min(items.length - 1, i + 1)); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(-1, i - 1)); }
                    else if (e.key === 'Escape') { setOpen(false); setActive(-1); }
                }}
            />
            <kbd aria-hidden="true">{mac ? '⌘K' : 'Ctrl K'}</kbd>
            {listOpen && (
                <div className="search-results" id="topbar-search-results" role="listbox" aria-label="Search results">
                    {items.length === 0 && <p className="search-empty">Nothing found for “{results.q}”.</p>}
                    {items.map((item, i) => (
                        <a
                            key={item.key}
                            id={`search-option-${i}`}
                            role="option"
                            aria-selected={i === active}
                            href={item.href}
                            className={i === active ? 'active' : undefined}
                            onMouseDown={(e) => { e.preventDefault(); go(item.href); }}
                        >
                            <span className={`search-kind ${item.kind}`}>{item.kind === 'client' ? <span className={`status-dot ${item.online ? 'is-online' : ''}`} /> : <Icon name={item.kind === 'onu' ? 'onu' : item.kind === 'device' ? 'server' : 'billing'} />}</span>
                            <span><strong>{item.title}</strong><small className={item.kind === 'client' ? 'mono' : undefined}>{item.sub}</small></span>
                        </a>
                    ))}
                    {showAll && (
                        <a href={`/clients?q=${encodeURIComponent(results.q)}`} className="search-all" onMouseDown={(e) => { e.preventDefault(); setOpen(false); router.get('/clients', { q: results.q }); }}>
                            All {results.clientTotal} matching clients
                        </a>
                    )}
                </div>
            )}
        </form>
    );
}

/** Workspace shell: sidebar, topbar and page feedback (legacy render()). */
export default function AppLayout({ title, heading = true, subtitle, actions, children }: {
    title: string;
    /** false: the page draws its own header (client, NAS, OLT and ONU pages). */
    heading?: boolean;
    /** One short line under the title: the current state, never an explanation. */
    subtitle?: ReactNode;
    /** Buttons beside the title, at most one primary. */
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { auth, flash, clock, appName, appVersion, pro, nav } = useShared();
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

    const place = placeOf(path);
    const user = auth.user;
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
                <button type="button" className="nav-close" aria-label="Close navigation" title="Close navigation" onClick={() => { setOpen(false); toggle.current?.focus(); }}>
                    <Icon name="close" />
                </button>
                <div className="brand">
                    <BrandLogo />
                    {/* A company name keeps the product name underneath, so people still know what this is. */}
                    <div><strong>{appName}</strong><small>{appName === 'RadiusOne' ? 'Client management' : 'RadiusOne'}</small></div>
                </div>
                <nav aria-label="Primary">
                    {NAV.filter(([, , permission]) => can(permission)).map(([href, label, , icon, navGroup, count]) => {
                        const active = path === href || (href !== '/' && path.startsWith(`${href}/`)) || (href === '/' && path === '/dashboard');
                        const heading = navGroup !== group ? <span className="nav-group-label">{navGroup}</span> : null;
                        group = navGroup;
                        const n = count ? nav?.[count] ?? null : null;
                        return (
                            <Fragment key={href}>
                                {heading}
                                <Link className={active ? 'active' : ''} href={href} aria-current={active ? 'page' : undefined}>
                                    <Icon name={icon} />
                                    <span>{label}</span>
                                    {PRO_NAV[href] && pro[PRO_NAV[href]] === false
                                        ? <span className="nav-pro">Pro</span>
                                        : count === 'weakOnus'
                                            ? !!n && <span className="nav-flag" title={`${n} ${n === 1 ? 'ONU has' : 'ONUs have'} a weak signal`}>{n}<span className="sr-only"> with weak signal</span></span>
                                            : n !== null && <span className="nav-count">{n.toLocaleString('en-US')}</span>}
                                </Link>
                            </Fragment>
                        );
                    })}
                    {/* The guide is on pingomni.com, for every operator. */}
                    <a href={DOCS_URL} target="_blank" rel="noopener noreferrer">
                        <Icon name="book" />
                        <span>Documentation</span>
                    </a>
                </nav>
                <div className="sidebar-foot">
                    <Link className="sidebar-user" href="/profile" title="My account">
                        <span className="sidebar-avatar" aria-hidden="true">{(user?.name || user?.username || '?').split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase()}</span>
                        <span><strong>{user?.name || user?.username}</strong><small>{user?.role ? user.role.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) : ''}</small></span>
                    </Link>
                    <ThemeToggle />
                    <button type="button" className="logout-button icon-button" title="Sign out" onClick={() => router.post('/logout')}>
                        <Icon name="logout" />
                        <span className="sr-only">Sign out</span>
                    </button>
                </div>
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
                        <nav className="crumbs" aria-label="Breadcrumb">
                            {place ? (
                                <>
                                    <span>{place.group}</span>
                                    {place.label.toLowerCase() === title.toLowerCase() ? <span aria-current="page">{title}</span> : <><Link href={place.href}>{place.label}</Link><span aria-current="page">{title}</span></>}
                                </>
                            ) : <span aria-current="page">{title}</span>}
                        </nav>
                    </div>
                    {can('clients.view') && <TopSearch key={search} initial={search} />}
                    <div className="operator">
                        <span className="topbar-clock" title="All times are shown in this timezone">{time} {clock.timezone}</span>
                    </div>
                </header>
                <div className="workspace-content" id="workspace-content" tabIndex={-1} ref={feedback}>
                    {heading && (
                        <div className={`page-head${actions ? ' with-actions' : ''}`}>
                            <div><h1>{title}</h1>{subtitle && <p>{subtitle}</p>}</div>
                            {actions && <div className="page-actions">{actions}</div>}
                        </div>
                    )}
                    {flash && <FlashMessage flash={flash} />}
                    {children}
                </div>
            </main>
            <div className="sr-only" id="feedback-announcer" role="status" aria-live="polite" aria-atomic="true">{announcement}</div>
        </>
    );
}
