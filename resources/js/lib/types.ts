export type FlashType = 'success' | 'danger' | 'warning' | 'info';

export interface Flash {
    type: FlashType;
    message: string;
}

export interface AuthUser {
    id: number;
    username: string;
    name: string;
    email: string;
    role: string;
    two_factor_enabled: boolean;
}

export type ProFeature = 'billing' | 'notifications' | 'monitoring' | 'backups' | 'branding';

export interface SharedProps {
    appName: string;
    /** RadiusOne release, e.g. "2.0.0"; null when signed out. */
    appVersion: string | null;
    auth: { user: AuthUser | null; permissions: string[] };
    flash: Flash | null;
    logo: string | null;
    clock: { time: string; now: number; timezone: string };
    /** System currency code (Settings → Regional): EUR, USD or ALL. */
    currency: string;
    /** RadiusOne Pro features (License::FEATURES) and whether each may run here; empty when signed out. */
    pro: Partial<Record<ProFeature, boolean>>;
    errors: Record<string, string>;
    [key: string]: unknown;
}

/** Server-paginated list, as returned by App\Http\Support\Paging::result(). */
export interface Paginated<T> {
    rows: T[];
    total: number;
    page: number;
    pages: number;
    size: number;
}

export type Option = { value: string; label: string };
