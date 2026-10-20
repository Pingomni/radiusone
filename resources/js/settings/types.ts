import { type TagData } from '@/components/ui';

export type Overview = { engine: TagData; rows: [string, string][] };

export type UpdateStatus = { state: string; step: string; message: string; from: string; to: string; at: string };
export type SoftwareUpdate = {
    current: string;
    ready: boolean;
    running: boolean;
    check: { at: string; current: string; latest: string; newer: boolean; tag: string; notes: string; problem: string } | null;
    status: UpdateStatus | null;
    log: string[];
};

export type Operator = { id: number; name: string; email: string; username: string; role: string; enabled: boolean; twoFactor: TagData; lastLogin: string; self: boolean };

export type Branding = { custom: boolean; remote: boolean; url: string; hasSetting: boolean; name: string; product: string };

export type Template = { subject: string; email_body: string; sms_body: string; email_enabled: string; sms_enabled: string };

export type Bulk = { channel: 'email' | 'sms'; q: string; rows: { id: number; username: string; recipient: string; eligibility: string }[]; more: boolean; selected: number[]; audience: string; subject: string; body: string };

export type PreviewItem = { username: string; recipient: string; reason: string; subject: string; body: string };

export type Preview = { token: string; channel: string; eligible: number; items: PreviewItem[]; sample: PreviewItem };

export type HistoryRow = { id: number; queued: string; username: string; template: string; channel: string; recipient: string; status: TagData; reason: string };

export type SmsGateway = {
    enabled: string; provider: string; country_code: string; method: string; url: string; body: string; success: string;
    twilio_sid: string; twilio_from: string; twilio_token_saved: boolean; infobip_base_url: string; infobip_from: string; infobip_api_key_saved: boolean;
};

export type EmailGateway = {
    enabled: string; provider: string; transport: string; host: string; port: string; encryption: string; username: string; from_email: string; from_name: string;
    reply_to: string; timeout: string; password_saved: boolean;
    mailgun_domain: string; mailgun_region: string; mailgun_api_key_saved: boolean;
};

export type AdminSettings = {
    enabled: boolean; emails: string; phones: string; alert_email: boolean; alert_sms: boolean; checks: string[]; report_enabled: boolean; report_time: string;
    alert_telegram: boolean; report_telegram: boolean; telegram_chats: string; telegram_token_saved: boolean; templates: Record<string, string>;
};

export type AdminEvent = { at: string; kind: TagData; text: string; sent: string };

export type Notifications = {
    view: 'templates' | 'gateways' | 'bulk' | 'preview' | 'history' | 'admin';
    admin?: { settings: AdminSettings; checks: Record<string, string>; recent: AdminEvent[]; templates: Record<string, { label: string; default: string }>; placeholders: Record<'alert' | 'report', string[]> };
    ready: { email: boolean; sms: boolean };
    gateway?: 'email' | 'sms' | null;
    providers?: Record<string, string>;
    sms?: SmsGateway;
    email?: EmailGateway;
    labels?: Record<string, string>;
    tokens?: string[];
    templates?: Record<string, Template>;
    template?: string | null;
    bulk?: Bulk | null;
    preview?: Preview | null;
    history?: { before: number; older: number | null; rows: HistoryRow[] };
};

export type BackupDestination = {
    id: string; type: 's3' | 'sftp'; name: string; enabled: boolean; keep: number; connected: boolean;
    provider?: string; endpoint?: string; region?: string; bucket?: string; prefix?: string; access_key?: string; secret_key_saved?: boolean;
    host?: string; port?: string; user?: string; path?: string; public_key?: string; fingerprint?: string;
};

export type BackupRun = {
    at: string; ok: boolean; error?: string; database?: { file: string; size: number }; documents?: { file: string; size: number };
    destinations: Record<string, { name: string; type: string; ok: boolean; message: string; removed: number }>; local_removed?: number;
};

export type Backups = {
    passphraseSet: boolean; localDays: number; localDaysOptions: Record<string, string>; destinations: BackupDestination[];
    types: Record<string, string>; keepOptions: Record<string, string>; status: BackupRun | null;
    local: { name: string; size: number; at: string }[]; localCount: number; s3Providers: Record<string, string>;
    manualReady: boolean; running: boolean;
};

export type RetentionRun = { sessions: number; logins: number; finished: boolean; at: string };

export type Retention = {
    periods: { sessions: number; logins: number };
    stats: { sessions: { rows: number; oldest: string }; logins: { rows: number; oldest: string }; lastRun: RetentionRun | null };
    options: { sessions: Record<string, string>; logins: Record<string, string> };
};

export type Documents = {
    values: Record<string, string>; defaults: Record<string, string>; labels: Record<string, string>; lines: Record<string, number>; placeholders: string[];
};

export type Regional = {
    timezone: string; timezones: Record<string, string>; timezoneLog: string[]; timezoneReady: boolean;
    currency: string; currencies: Record<string, string>; issuedOther: number;
};
