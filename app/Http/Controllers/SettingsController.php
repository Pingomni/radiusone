<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Operators;
use App\Http\Support\Present;
use App\Support\App;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Branding;
use App\Support\CustomerNotifications;
use App\Support\Db;
use App\Support\NotificationService;
use App\Support\Retention;
use App\Support\SchemaCompatibility;
use App\Support\Settings;
use App\Support\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Settings: overview, users, notifications (gateways, templates, bulk send, history) and branding. */
final class SettingsController extends Controller
{
    private const NOTICES = '/settings?tab=notifications';

    public function settings(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'overview');
        $tab = $tab === 'custom' ? 'branding' : $tab;
        $tab = in_array($tab, ['overview', 'users', 'notifications', 'branding', 'retention'], true) ? $tab : 'overview';

        $userCount = (int) Db::value('SELECT COUNT(*) FROM portal_users');
        $enabledUsers = (int) Db::value('SELECT COUNT(*) FROM portal_users WHERE enabled = 1');
        $logoPath = Settings::value('portal_logo_path', '');
        $customLogo = Branding::source($logoPath) !== null;
        $remoteLogo = $customLogo && str_starts_with(strtolower($logoPath), 'https://');

        return Inertia::render('settings/Settings', [
            'tab' => $tab,
            'overview' => fn () => $this->overview($userCount, $enabledUsers),
            'users' => fn () => Auth::can('portal_users.manage') ? $this->users() : null,
            'usersSummary' => ['enabled' => $enabledUsers, 'total' => $userCount],
            'roleDescriptions' => Operators::roleDescriptions(),
            'branding' => [
                'custom' => $customLogo,
                'remote' => $remoteLogo,
                'url' => $remoteLogo ? $logoPath : '',
                'hasSetting' => $logoPath !== '',
            ],
            // Only the active tab's notification data is built: it may query many customers.
            'notifications' => $tab === 'notifications' ? $this->notifications($request) : null,
            'retention' => $tab === 'retention' ? [
                'periods' => Retention::settings(),
                'stats' => Retention::stats(),
                'options' => ['sessions' => Retention::SESSIONS, 'logins' => Retention::LOGINS],
            ] : null,
        ]);
    }

    private function overview(int $userCount, int $enabledUsers): array
    {
        $engine = (string) (Db::value('SELECT setting_value FROM settings WHERE setting_key = "web_server_engine"') ?: App::env('WEB_SERVER_ENGINE', 'nginx'));
        $twoFactorUsers = (int) Db::value('SELECT COUNT(*) FROM portal_users WHERE two_factor_enabled = 1');
        $backupPath = rtrim((string) App::env('BACKUP_PATH', '/var/backups/freeradius-web'), '/');
        $backups = glob($backupPath . '/*.sql.gz') ?: [];
        usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $lastBackup = $backups[0] ?? '';
        return [
            'engine' => Present::tag('engine ' . $engine, $engine === 'nginx' ? 'success' : ''),
            'rows' => [
                ['Environment', (string) App::env('APP_ENV', 'production')],
                ['Operators', $enabledUsers . '/' . $userCount . ' enabled - ' . $twoFactorUsers . ' with 2FA'],
                ['Last backup', $lastBackup ? basename($lastBackup) : 'none'],
                ['Backup time', $lastBackup ? date('Y-m-d H:i', (int) filemtime($lastBackup)) : 'No SQL backup found'],
                ['Documents', (string) App::env('DOCUMENT_STORAGE', App::root('storage/documents'))],
                ['Backups path', (string) App::env('BACKUP_PATH', App::root('var/backups'))],
                ['radclient', (string) App::env('RADCLIENT_BIN', '/usr/bin/radclient')],
            ],
        ];
    }

    private function users(): array
    {
        $self = Auth::id();
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'username' => (string) $r['username'],
            'role' => (string) $r['role'],
            'enabled' => (bool) $r['enabled'],
            'twoFactor' => Operators::twoFactorTag($r),
            'lastLogin' => (string) ($r['last_login_at'] ?: '-'),
            'self' => (int) $r['id'] === $self,
        ], Db::select('SELECT id, name, email, username, role, enabled, two_factor_enabled, two_factor_secret, last_login_at FROM portal_users ORDER BY role, username'));
    }

    private function notifications(Request $request): array
    {
        SchemaCompatibility::require('notifications');
        return NotificationService::withSettings(function () use ($request): array {
            $ready = ['email' => CustomerNotifications::ready('email'), 'sms' => CustomerNotifications::ready('sms')];
            $anyReady = $ready['email'] || $ready['sms'];
            $legacy = (string) $request->query('channel', '');
            $view = (string) $request->query('view', in_array($legacy, ['sms', 'email'], true) ? 'gateways' : ($anyReady ? 'templates' : 'gateways'));
            if (!in_array($view, ['templates', 'gateways', 'bulk', 'preview', 'history'], true)) {
                $view = $anyReady ? 'templates' : 'gateways';
            }
            $data = ['view' => $view, 'ready' => $ready];

            if ($view === 'gateways') {
                $gateway = (string) $request->query('gateway', in_array($legacy, ['email', 'sms'], true) ? $legacy : '');
                $data['gateway'] = in_array($gateway, ['email', 'sms'], true) ? $gateway : null;
                if ($data['gateway'] === 'sms') {
                    $data['sms'] = NotificationService::smsGatewaySettings();
                } elseif ($data['gateway'] === 'email') {
                    $data['email'] = array_diff_key(NotificationService::emailGatewaySettings(), ['password' => true]);
                }
            } elseif ($view === 'templates') {
                $templates = CustomerNotifications::templates();
                $key = (string) $request->query('template', '');
                $data['labels'] = CustomerNotifications::LABELS;
                $data['tokens'] = CustomerNotifications::TOKENS;
                $data['templates'] = $templates;
                $data['template'] = isset($templates[$key]) ? $key : null;
            } elseif ($view === 'bulk') {
                $data['bulk'] = $anyReady ? $this->bulkComposer($request, $ready) : null;
            } elseif ($view === 'preview') {
                $data['preview'] = $this->bulkPreview($request);
            } else {
                $data['history'] = $this->history($request);
            }
            return $data;
        });
    }

    private function bulkComposer(Request $request, array $ready): array
    {
        $channel = (string) $request->query('send_channel', $ready['email'] ? 'email' : 'sms');
        $channel = in_array($channel, ['email', 'sms'], true) ? $channel : 'email';
        $template = CustomerNotifications::templates()['custom'];
        $draft = $request->session()->get('notification_draft', []);
        $editing = $request->has('edit') && ($draft['actor'] ?? null) === Auth::id() && ($draft['expires'] ?? 0) >= time();
        $source = $editing ? ($draft['source'] ?? []) : [];
        if ($editing) {
            $channel = $draft['channel'];
        }
        $selected = array_map('intval', $source['recipients'] ?? []);
        $selectedSql = $selected ? implode(',', $selected) : '0';
        $q = trim((string) $request->query('recipient_q', ''));
        $clients = Db::select('SELECT id,username,first_name,last_name,company_name,email,phone,cell_phone,email_alert,sms_alert,account_status FROM customer_profiles WHERE account_status<>"archived" AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR id IN (' . $selectedSql . ')) ORDER BY id IN (' . $selectedSql . ') DESC,username LIMIT 201', array_fill(0, 3, '%' . $q . '%'));
        $rows = [];
        foreach (array_slice($clients, 0, 200) as $c) {
            [$recipient, $reason] = CustomerNotifications::eligibility($c, $channel);
            $rows[] = ['id' => (int) $c['id'], 'username' => (string) $c['username'], 'recipient' => (string) ($recipient ?: '—'), 'eligibility' => (string) ($reason ?: 'Ready')];
        }
        return [
            'channel' => $channel,
            'q' => $q,
            'rows' => $rows,
            'more' => count($clients) > 200,
            'selected' => $selected,
            'audience' => (string) ($source['audience'] ?? 'selected'),
            'subject' => (string) ($source['subject'] ?? $template['subject']),
            'body' => (string) ($source['body'] ?? $template[$channel . '_body']),
        ];
    }

    private function bulkPreview(Request $request): ?array
    {
        $draft = $request->session()->get('notification_draft');
        if (!$draft || ($draft['actor'] ?? null) !== Auth::id() || $draft['expires'] < time()) {
            return null;
        }
        $items = array_map(static fn (array $i) => [
            'username' => (string) $i['username'],
            'recipient' => (string) ($i['recipient'] ?: '—'),
            'reason' => (string) ($i['reason'] ?: ''),
            'subject' => (string) $i['subject'],
            'body' => (string) $i['body'],
        ], $draft['items']);
        $sample = current(array_filter($items, static fn ($i) => $i['reason'] === '')) ?: $items[0];
        return ['token' => $draft['token'], 'channel' => $draft['channel'], 'eligible' => (int) $draft['eligible'], 'items' => $items, 'sample' => $sample];
    }

    private function history(Request $request): array
    {
        $before = max(0, (int) $request->query('before', 0));
        $logs = Db::select('SELECT d.id,d.created_at,d.channel,d.state,d.reason,d.recipient,e.template_key,c.username FROM notification_deliveries d JOIN notification_events e ON e.id=d.event_id JOIN customer_profiles c ON c.id=d.customer_id ' . ($before ? 'WHERE d.id<? ' : '') . 'ORDER BY d.id DESC LIMIT 51', $before ? [$before] : []);
        return [
            'before' => $before,
            'older' => count($logs) > 50 ? (int) $logs[49]['id'] : null,
            'rows' => array_map(static fn (array $log) => [
                'id' => (int) $log['id'],
                'queued' => (string) $log['created_at'],
                'username' => (string) $log['username'],
                'template' => CustomerNotifications::LABELS[$log['template_key']] ?? (string) $log['template_key'],
                'channel' => strtoupper((string) $log['channel']),
                'recipient' => (string) $log['recipient'],
                'status' => Present::tag($log['state'] === 'sent' ? 'Accepted by gateway' : ucfirst((string) $log['state']), $log['state'] === 'sent' ? 'success' : ($log['state'] === 'uncertain' ? 'warning' : '')),
                'reason' => (string) ($log['reason'] ?: '—'),
            ], array_slice($logs, 0, 50)),
        ];
    }

    public function legacyNotificationPage(string $channel): RedirectResponse
    {
        return redirect('/settings?tab=notifications&view=gateways' . ($channel === 'general' ? '' : '&gateway=' . $channel));
    }

    public function saveLogo(Request $request): RedirectResponse
    {
        try {
            $upload = Security::uploadInfo('portal_logo', ['image/png', 'image/jpeg', 'image/webp'], 2 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/settings?tab=branding');
        }
        if (!$upload) {
            return $this->fail('Select a logo file to upload.', '/settings?tab=branding');
        }
        $dir = Branding::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return $this->fail('Could not create branding storage. Ask your administrator to check its permissions.', '/settings?tab=branding');
        }
        if (!is_writable($dir)) {
            return $this->fail('Branding storage is not writable. Ask your administrator to check its permissions.', '/settings?tab=branding');
        }
        $oldPath = Settings::value('portal_logo_path', '');
        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $upload['extension'];
        try {
            $request->file('portal_logo')->move($dir, $filename);
        } catch (Throwable) {
            return $this->fail('Could not save uploaded logo.', '/settings?tab=branding');
        }
        $target = $dir . '/' . $filename;
        chmod($target, 0644);
        try {
            Settings::save('portal_logo_path', '/uploads/branding/' . $filename);
        } catch (Throwable $e) {
            unlink($target);
            throw $e;
        }
        self::removeUpload($oldPath);
        Audit::log('update', 'settings', 'branding_logo', null, ['logo' => '/uploads/branding/' . $filename]);
        return $this->ok('/settings?tab=branding', 'Custom logo uploaded.');
    }

    public function saveLogoUrl(Request $request): RedirectResponse
    {
        try {
            $url = Branding::httpsUrl((string) $request->input('portal_logo_url', ''));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), '/settings?tab=branding');
        }
        $oldPath = Settings::value('portal_logo_path', '');
        Settings::save('portal_logo_path', $url);
        self::removeUpload($oldPath);
        Audit::log('update', 'settings', 'branding_logo', null, ['logo' => $url]);
        return $this->ok('/settings?tab=branding', 'Logo URL saved. Your branding is now applied to the workspace and sign-in screen.');
    }

    public function deleteLogo(): RedirectResponse
    {
        $oldPath = Settings::value('portal_logo_path', '');
        Settings::save('portal_logo_path', '');
        self::removeUpload($oldPath);
        Audit::log('delete', 'settings', 'branding_logo');
        return $this->ok('/settings?tab=branding', 'Custom logo removed.');
    }

    public function saveNotificationTemplates(Request $request): RedirectResponse
    {
        $key = (string) $request->input('template', '');
        try {
            CustomerNotifications::saveTemplate($key, $this->formInput($request));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), self::NOTICES . '&view=templates&template=' . rawurlencode($key));
        }
        Audit::log('update', 'settings', 'notification_template_' . $key);
        return $this->ok(self::NOTICES . '&view=templates', 'Template saved.');
    }

    public function notificationBulkPreview(Request $request): RedirectResponse
    {
        try {
            $request->session()->put('notification_draft', CustomerNotifications::preview($this->formInput($request), (int) Auth::id()));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), self::NOTICES . '&view=bulk');
        }
        return redirect(self::NOTICES . '&view=preview');
    }

    public function notificationBulkConfirm(Request $request): RedirectResponse
    {
        $draft = $request->session()->get('notification_draft', []);
        if (!isset($draft['token']) || !hash_equals($draft['token'], (string) $request->input('draft_token', ''))) {
            return $this->fail('This preview is no longer available. Review your message again.', self::NOTICES . '&view=bulk');
        }
        try {
            $id = CustomerNotifications::confirm($draft, (int) Auth::id());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), self::NOTICES . '&view=bulk');
        }
        $request->session()->forget('notification_draft');
        Audit::log('queue', 'notifications', (string) $id, null, ['count' => $draft['eligible'], 'channel' => $draft['channel']]);
        return $this->ok(self::NOTICES . '&view=history', 'Messages queued. Follow their progress in History.');
    }

    public function notificationLegacy(): RedirectResponse
    {
        return $this->fail('Notifications have moved. Use Templates for automatic messages or Send bulk message to review a send.', self::NOTICES);
    }

    public function saveRetention(Request $request): RedirectResponse
    {
        $before = Retention::settings();
        try {
            Retention::save((int) $request->input('sessions', -1), (int) $request->input('logins', -1));
        } catch (RuntimeException $e) {
            return $this->failKeepingInput($e, '/settings?tab=retention');
        }
        Audit::log('update', 'setting', 'retention', $before, Retention::settings());
        return $this->ok('/settings?tab=retention', 'Retention saved. Older records are deleted in the nightly cleanup at 03:45 UTC.');
    }

    public function saveSmsGateway(Request $request): RedirectResponse
    {
        return $this->saveGateway($request, 'sms', static fn (array $input) => NotificationService::saveSmsGateway($input),
            ['enabled' => $request->has('sms_gateway_enabled'), 'method' => $request->input('sms_gateway_method', 'GET')], 'SMS gateway settings saved.');
    }

    public function saveEmailGateway(Request $request): RedirectResponse
    {
        return $this->saveGateway($request, 'email', static fn (array $input) => NotificationService::saveEmailGateway($input),
            ['enabled' => $request->has('email_gateway_enabled'), 'transport' => $request->input('email_gateway_transport', 'smtp')], 'Email gateway settings saved.');
    }

    private function saveGateway(Request $request, string $channel, \Closure $save, array $audit, string $message): RedirectResponse
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $save($this->formInput($request));
            $pdo->commit();
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            return $this->fail($e->getMessage(), self::NOTICES . '&view=gateways&gateway=' . $channel);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Audit::log('update', 'settings', $channel . '_gateway', null, $audit);
        return $this->ok(self::NOTICES . '&view=gateways', $message);
    }

    private static function removeUpload(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/uploads/branding/')) {
            return;
        }
        $file = Branding::file(basename($path));
        if ($file) {
            unlink($file);
        }
    }
}
