<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Support\MonitoringData;
use App\Http\Support\Present;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Operator alerts and the daily report, sent through the customer Email/SMS gateways to the admin
 * recipients in Settings → Notifications → Admin alerts. frweb:admin-alerts runs every minute:
 * a problem must be seen on two checks in a row before it alerts (a restart is not an outage),
 * and a "resolved" message follows once it clears. State and recent events live in the settings
 * table (notification_admin_state), so no extra schema is needed. Telegram (a bot posting to the
 * listed chats) is only used here, never for customer messages.
 */
final class AdminAlerts
{
    public const CHECKS = [
        'services' => 'Server services stopped (FreeRADIUS, MariaDB, Nginx, PHP-FPM, Cron)',
        'nas' => 'NAS not responding (ping or SNMP)',
        'resources' => 'Disk or memory almost full on the server',
        'jobs' => 'Background job stuck',
    ];
    /**
     * Message templates (Settings → Notifications → Admin alerts → Message templates); blank uses the default.
     * key => [label, default, max characters]
     */
    public const TEMPLATES = [
        'alert_subject' => ['Alert subject', '[{app}] {summary} on {host}', 150],
        'alert_body' => ['Alert message', "{events}\n\nServer: {host}\nTime: {time}\n\n{open}", 2000],
        'alert_sms' => ['Alert SMS / short text', '{app}: {events_short}', 300],
        'report_subject' => ['Daily report subject', '[{app}] Daily report {date} · {status}', 150],
        'report_intro' => ['Daily report opening text', '', 1000],
    ];
    /** Placeholders per template, shown under the fields. */
    public const PLACEHOLDERS = [
        'alert' => ['app', 'host', 'summary', 'events', 'events_short', 'open', 'time'],
        'report' => ['app', 'host', 'date', 'status', 'time'],
    ];
    private const STATE = 'notification_admin_state';
    private const LOG_SIZE = 30;

    public static function settings(): array
    {
        $checks = array_values(array_intersect(array_keys(self::CHECKS), explode(',', Settings::value('notification_admin_checks', implode(',', array_keys(self::CHECKS))))));
        return [
            'enabled' => Settings::value('notification_admin_enabled', '0') === '1',
            'emails' => Settings::value('notification_admin_emails', ''),
            'phones' => Settings::value('notification_admin_phones', ''),
            'alert_email' => Settings::value('notification_admin_alert_email', '1') === '1',
            'alert_sms' => Settings::value('notification_admin_alert_sms', '0') === '1',
            'alert_telegram' => Settings::value('notification_admin_alert_telegram', '0') === '1',
            'telegram_token' => Settings::value('notification_admin_telegram_token', ''),
            'telegram_chats' => Settings::value('notification_admin_telegram_chats', ''),
            'report_telegram' => Settings::value('notification_admin_report_telegram', '0') === '1',
            'checks' => $checks,
            'report_enabled' => Settings::value('notification_admin_report_enabled', '0') === '1',
            'report_time' => Settings::value('notification_admin_report_time', '07:00'),
            // The text in use: the saved template, or the default.
            'templates' => array_map(static fn (string $key) => Settings::value('notification_admin_tpl_' . $key, '') ?: self::TEMPLATES[$key][1], array_combine(array_keys(self::TEMPLATES), array_keys(self::TEMPLATES))),
        ];
    }

    /** A template with its placeholders filled; the default when none is saved. */
    private static function render(string $key, array $values): string
    {
        $template = Settings::value('notification_admin_tpl_' . $key, '');
        $template = $template !== '' ? $template : self::TEMPLATES[$key][1];
        $text = strtr($template, array_combine(array_map(static fn ($k) => '{' . $k . '}', array_keys($values)), array_map('strval', $values)));
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public static function save(array $input): void
    {
        $emails = self::list((string) ($input['emails'] ?? ''));
        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Not a valid email address: $email");
            }
        }
        $phones = self::list((string) ($input['phones'] ?? ''));
        foreach ($phones as $phone) {
            if (!preg_match('/^\+?[0-9][0-9 ()-]{5,24}$/D', $phone)) {
                throw new InvalidArgumentException("Not a valid phone number: $phone");
            }
        }
        $chats = self::list((string) ($input['telegram_chats'] ?? ''));
        foreach ($chats as $chat) {
            if (!preg_match('/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/D', $chat)) {
                throw new InvalidArgumentException("Not a Telegram chat ID: $chat (a number such as 123456789 or -1001234567890, or @channelname)");
            }
        }
        $token = trim((string) ($input['telegram_token'] ?? ''));
        if ($token !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,50}$/D', $token)) {
            throw new InvalidArgumentException('That does not look like a Telegram bot token (it looks like 123456789:ABC…, from @BotFather).');
        }
        $telegram = !empty($input['alert_telegram']) || !empty($input['report_telegram']);
        if ($telegram && ($token === '' && Settings::value('notification_admin_telegram_token', '') === '' || !$chats)) {
            throw new InvalidArgumentException('For Telegram, enter the bot token and at least one chat ID.');
        }
        $time = trim((string) ($input['report_time'] ?? '07:00'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw new InvalidArgumentException('Enter the report time as HH:MM.');
        }
        $enabled = !empty($input['enabled']);
        if ($enabled && !$emails && !$phones && !$chats) {
            throw new InvalidArgumentException('Add at least one email address, phone number or Telegram chat to receive alerts.');
        }
        $templates = [];
        foreach (self::TEMPLATES as $key => [$label, , $max]) {
            $value = trim(str_replace("\r", '', (string) (((array) ($input['templates'] ?? []))[$key] ?? '')));
            if (mb_strlen($value) > $max) {
                throw new InvalidArgumentException("$label: at most $max characters.");
            }
            $allowed = self::PLACEHOLDERS[str_starts_with($key, 'report') ? 'report' : 'alert'];
            if (preg_match('/\{(?!(?:' . implode('|', $allowed) . ')\})[a-z_]*\}/', $value, $m)) {
                throw new InvalidArgumentException("$label: {$m[0]} cannot be used here. Available: {" . implode('}, {', $allowed) . '}.');
            }
            $templates[$key] = $value;
        }
        $checks = array_values(array_intersect(array_keys(self::CHECKS), (array) ($input['checks'] ?? [])));
        Settings::save('notification_admin_enabled', $enabled ? '1' : '0');
        Settings::save('notification_admin_emails', implode(', ', $emails));
        Settings::save('notification_admin_phones', implode(', ', $phones));
        Settings::save('notification_admin_alert_email', !empty($input['alert_email']) ? '1' : '0');
        Settings::save('notification_admin_alert_sms', !empty($input['alert_sms']) ? '1' : '0');
        Settings::save('notification_admin_alert_telegram', !empty($input['alert_telegram']) ? '1' : '0');
        Settings::save('notification_admin_report_telegram', !empty($input['report_telegram']) ? '1' : '0');
        Settings::save('notification_admin_telegram_chats', implode(', ', $chats));
        if ($token !== '') {
            Settings::save('notification_admin_telegram_token', $token, true);
        } elseif (!empty($input['telegram_token_clear'])) {
            Settings::save('notification_admin_telegram_token', '');
        }
        Settings::save('notification_admin_checks', implode(',', $checks));
        Settings::save('notification_admin_report_enabled', !empty($input['report_enabled']) ? '1' : '0');
        Settings::save('notification_admin_report_time', $time);
        foreach ($templates as $key => $value) {
            // The default text, shown pre-filled, stays "the default" so a later change of default applies.
            Settings::save('notification_admin_tpl_' . $key, $value === self::TEMPLATES[$key][1] ? '' : $value);
        }
    }

    /** @return list<array{at:int,kind:string,text:string,sent:string}> newest first */
    public static function recent(): array
    {
        return self::state()['log'];
    }

    /** @return array<string, string> problem key => description, for the enabled checks */
    public static function problems(array $checks): array
    {
        $problems = [];
        if (in_array('services', $checks, true)) {
            foreach (ServerMonitor::services() as $unit => $s) {
                if ($s['state'] !== 'active') {
                    $problems['service:' . $unit] = $s['label'] . ' is ' . $s['state'];
                }
            }
        }
        if (in_array('resources', $checks, true)) {
            $disk = ServerMonitor::disk();
            if ($disk && $disk['used'] / $disk['total'] >= 0.9) {
                $problems['disk'] = 'Disk / is ' . round($disk['used'] / $disk['total'] * 100) . '% full';
            }
            $memory = ServerMonitor::memory();
            if ($memory && $memory['used'] / $memory['total'] >= 0.95) {
                $problems['memory'] = 'Memory is ' . round($memory['used'] / $memory['total'] * 100) . '% used';
            }
        }
        if (in_array('nas', $checks, true) || in_array('jobs', $checks, true)) {
            $overview = MonitoringData::overview();
            if (in_array('nas', $checks, true)) {
                foreach ($overview['nas'] as $nas) {
                    if ($nas['danger'] && !$nas['radiusDisabled']) {
                        $problems['nas:' . $nas['id']] = 'NAS ' . $nas['name'] . ' (' . $nas['ip'] . '): ' . $nas['state']['text'];
                    }
                }
            }
            if (in_array('jobs', $checks, true)) {
                foreach ($overview['jobs'] as $job) {
                    if ($job['tone'] === 'danger') {
                        $problems['job:' . $job['label']] = $job['label'] . ': ' . $job['detail'];
                    }
                }
            }
        }
        return $problems;
    }

    /** One scheduled run: alert on new and resolved problems, then the daily report when due. */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $settings = self::settings();
        if (!$settings['enabled']) {
            return ['enabled' => false];
        }
        $state = self::state();
        $current = self::problems($settings['checks']);
        $opened = [];
        $resolved = [];
        foreach ($current as $key => $text) {
            if (isset($state['active'][$key])) {
                $state['active'][$key]['text'] = $text;
            } elseif (isset($state['pending'][$key])) {
                // Seen on two checks in a row: a real problem, not a restart.
                $state['active'][$key] = ['text' => $text, 'since' => $state['pending'][$key]];
                $opened[$key] = $text;
            }
        }
        foreach ($state['active'] as $key => $problem) {
            if (!isset($current[$key])) {
                $resolved[$key] = $problem['text'] . ' (lasted ' . Present::uptime(max(60, $now - (int) $problem['since'])) . ')';
                unset($state['active'][$key]);
            }
        }
        $state['pending'] = [];
        foreach ($current as $key => $text) {
            if (!isset($state['active'][$key])) {
                $state['pending'][$key] = $now;
            }
        }

        if ($opened || $resolved) {
            $host = (string) gethostname();
            $lines = array_merge(array_map(static fn ($t) => 'PROBLEM: ' . $t, $opened), array_map(static fn ($t) => 'RESOLVED: ' . $t, $resolved));
            $values = [
                'app' => self::appName(),
                'host' => $host,
                'summary' => $opened ? count($opened) . ' problem' . (count($opened) === 1 ? '' : 's') : 'All clear',
                'events' => implode("\n", $lines),
                'events_short' => implode('; ', $lines),
                'open' => $state['active'] ? "Still open:\n" . implode("\n", array_map(static fn ($p) => '- ' . $p['text'], $state['active'])) : '',
                'time' => date('Y-m-d H:i T', $now),
            ];
            $sent = self::deliver($settings, self::render('alert_subject', $values), self::render('alert_body', $values) . "\n", self::render('alert_sms', $values), self::alertChannels($settings));
            foreach ($opened as $text) {
                self::log($state, $now, 'problem', $text, $sent);
            }
            foreach ($resolved as $text) {
                self::log($state, $now, 'resolved', $text, $sent);
            }
        }

        $reported = false;
        $today = date('Y-m-d', $now);
        if ($settings['report_enabled'] && date('H:i', $now) >= $settings['report_time'] && ($state['report_date'] ?? '') !== $today) {
            $state['report_date'] = $today;
            self::saveState($state);
            [$subject, $body] = self::report($now);
            $sent = self::deliver($settings, $subject, $body, null, self::reportChannels($settings));
            self::log($state, $now, 'report', 'Daily report', $sent);
            $reported = true;
        }
        self::saveState($state);
        return ['problems' => count($state['active']), 'opened' => count($opened), 'resolved' => count($resolved), 'report' => $reported];
    }

    /** Test message to every recipient, on the channels chosen for alerts. */
    public static function test(): string
    {
        $settings = self::settings();
        $state = self::state();
        $sent = self::deliver($settings, '[' . self::appName() . '] Test alert', "This is a test of the admin alerts from " . gethostname() . ".\nAlerts and reports will arrive like this.\n", self::appName() . ': test admin alert from ' . gethostname(), self::alertChannels($settings), true);
        self::log($state, time(), 'test', 'Test alert', $sent);
        self::saveState($state);
        return $sent;
    }

    /** The daily report, sent now on request. */
    public static function sendReport(): string
    {
        $settings = self::settings();
        $state = self::state();
        [$subject, $body] = self::report(time());
        $sent = self::deliver($settings, $subject, $body, null, self::reportChannels($settings), true);
        self::log($state, time(), 'report', 'Daily report (sent manually)', $sent);
        self::saveState($state);
        return $sent;
    }

    /** @return array{0:string,1:string} subject and plain-text body */
    public static function report(int $now): array
    {
        $host = (string) gethostname();
        $overview = MonitoringData::overview();
        $open = self::state()['active'];
        $values = ['app' => self::appName(), 'host' => $host, 'date' => date('Y-m-d', $now), 'time' => date('Y-m-d H:i T', $now),
            'status' => $open ? count($open) . ' open problem' . (count($open) === 1 ? '' : 's') : 'all OK'];
        $out = [];
        $intro = self::render('report_intro', $values);
        if ($intro !== '') {
            $out[] = $intro;
            $out[] = '';
        }
        $out[] = self::appName() . ' daily report · ' . $host . ' · ' . date('Y-m-d H:i T', $now);
        $out[] = '';
        $out[] = $open ? 'OPEN PROBLEMS (' . count($open) . ')' : 'No open problems.';
        foreach ($open as $p) {
            $out[] = '- ' . $p['text'] . ' (since ' . date('Y-m-d H:i', (int) $p['since']) . ')';
        }

        $out[] = '';
        $out[] = 'SERVER';
        $uptime = ServerMonitor::uptime();
        $out[] = '- Uptime: ' . ($uptime !== null ? Present::uptime($uptime) : '-');
        foreach ($overview['server']['items'] as $item) {
            $out[] = '- ' . $item['label'] . ': ' . $item['value'];
        }
        $out[] = '';
        $out[] = 'SERVICES';
        foreach (ServerMonitor::services() as $s) {
            $out[] = '- ' . $s['label'] . ': ' . ($s['state'] === 'active' ? 'running' . ($s['since'] ? ', up ' . Present::uptime(max(0, $now - $s['since'])) : '') : strtoupper($s['state']));
        }

        $auth = ServerMonitor::authStats(1440);
        $out[] = '';
        $out[] = 'RADIUS, LAST 24 HOURS';
        $out[] = '- Logins accepted: ' . $auth['accepts'];
        $out[] = '- Logins rejected: ' . $auth['rejects'];

        $out[] = '';
        $out[] = 'NAS DEVICES (' . count($overview['nas']) . ')';
        foreach ($overview['nas'] as $nas) {
            $items = array_column($nas['items'], 'value', 'label');
            $out[] = '- ' . $nas['name'] . ' (' . $nas['ip'] . '): ' . $nas['state']['text'] . ' · ' . ($items['Online now'] ?? '0') . ' online · last accounting ' . ($items['Last accounting'] ?? '-');
        }

        try {
            $customers = Db::row('SELECT COUNT(*) AS total, COALESCE(SUM(enabled = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE())), 0) AS active,
                COALESCE(SUM(expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY), 0) AS expiring, COALESCE(SUM(expiry_date < CURDATE()), 0) AS expired FROM customer_profiles');
            $out[] = '';
            $out[] = 'CUSTOMERS';
            $out[] = '- Total: ' . $customers['total'] . ' · active: ' . $customers['active'] . ' · expired: ' . $customers['expired'];
            $out[] = '- Expiring in the next 7 days: ' . $customers['expiring'];
        } catch (Throwable) {
        }

        $out[] = '';
        $out[] = 'BACKGROUND JOBS';
        foreach ($overview['jobs'] as $job) {
            $detail = trim(preg_replace('/^' . preg_quote($job['state'], '/') . '[;.]?\s*/i', '', $job['detail']) ?? '');
            $out[] = '- ' . $job['label'] . ': ' . $job['state'] . ($detail !== '' ? ' (' . $detail . ')' : '');
        }
        $events = array_filter(self::state()['log'], static fn ($e) => in_array($e['kind'], ['problem', 'resolved'], true) && $e['at'] >= $now - 86400);
        $out[] = '';
        $out[] = 'ALERTS IN THE LAST 24 HOURS: ' . ($events ? '' : 'none');
        foreach (array_reverse($events) as $e) {
            $out[] = '- ' . date('H:i', $e['at']) . ' ' . strtoupper($e['kind']) . ': ' . $e['text'];
        }

        return [self::render('report_subject', $values), implode("\n", $out) . "\n"];
    }

    /**
     * Sends to every admin recipient. Returns a short "sent to…" summary; one failed recipient does
     * not stop the others. With $strict, nothing accepted throws so the operator sees the reason.
     */
    private static function deliver(array $settings, string $subject, string $body, ?string $sms, array $channels, bool $strict = false): string
    {
        $emails = !empty($channels['email']) ? self::list($settings['emails']) : [];
        $phones = !empty($channels['sms']) && $sms !== null ? self::list($settings['phones']) : [];
        $chats = !empty($channels['telegram']) ? self::list($settings['telegram_chats']) : [];
        if ($strict && !$emails && !$phones && !$chats) {
            throw new RuntimeException('No recipients for this message: add an email address, phone number or Telegram chat, and turn its channel on.');
        }
        $ok = [];
        $failed = [];
        NotificationService::withSettings(static function () use ($emails, $phones, $subject, $body, $sms, &$ok, &$failed): void {
            foreach ($emails as $to) {
                try {
                    if (!CustomerNotifications::ready('email')) {
                        throw new RuntimeException('email gateway not enabled');
                    }
                    NotificationService::sendEmail(['email' => $to], $subject, $body, true);
                    $ok[] = $to;
                } catch (Throwable $e) {
                    $failed[] = $to . ' (' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e)) . ')';
                }
            }
            foreach ($phones as $to) {
                try {
                    if (!CustomerNotifications::ready('sms')) {
                        throw new RuntimeException('SMS gateway not enabled');
                    }
                    NotificationService::sendSms([], $to, mb_substr((string) $sms, 0, 300), true);
                    $ok[] = $to;
                } catch (Throwable $e) {
                    $failed[] = $to . ' (' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e)) . ')';
                }
            }
        });
        foreach ($chats as $chat) {
            try {
                self::telegram((string) $settings['telegram_token'], $chat, $subject . "\n\n" . $body);
                $ok[] = 'Telegram ' . $chat;
            } catch (Throwable $e) {
                $failed[] = 'Telegram ' . $chat . ' (' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e)) . ')';
            }
        }
        if ($strict && !$ok) {
            throw new RuntimeException('Not sent: ' . implode('; ', $failed));
        }
        return ($ok ? 'sent to ' . implode(', ', $ok) : 'not sent') . ($failed ? '; failed: ' . implode('; ', $failed) : '');
    }

    private static function alertChannels(array $s): array
    {
        return ['email' => $s['alert_email'], 'sms' => $s['alert_sms'], 'telegram' => $s['alert_telegram']];
    }

    /** The report is long: email, plus Telegram when chosen; never SMS. */
    private static function reportChannels(array $s): array
    {
        return ['email' => true, 'sms' => false, 'telegram' => $s['report_telegram']];
    }

    /** Posts one plain-text message through the Bot API (4096 characters at most). */
    private static function telegram(string $token, string $chat, string $text): void
    {
        if ($token === '') {
            throw new RuntimeException('bot token missing');
        }
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3990) . "\n…";
        }
        [$status, $response] = ProviderHttp::request('POST', 'https://api.telegram.org/bot' . $token . '/sendMessage', ['api.telegram.org'],
            ['Content-Type' => 'application/json'], json_encode(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true], JSON_THROW_ON_ERROR), 15);
        $json = ProviderHttp::json($response);
        if ($status !== 200 || empty($json['ok'])) {
            throw new RuntimeException('Telegram did not accept the message (HTTP ' . $status . '): ' . ($json['description'] ?? 'no reason given'));
        }
    }

    /**
     * Chats that recently wrote to the bot (or added it), so the operator can copy their IDs.
     * @return list<string> "id · name"
     */
    public static function telegramChats(): array
    {
        $token = self::settings()['telegram_token'];
        if ($token === '') {
            throw new RuntimeException('Save the bot token first.');
        }
        [$status, $response] = ProviderHttp::request('GET', 'https://api.telegram.org/bot' . $token . '/getUpdates?limit=100', ['api.telegram.org'], [], null, 15);
        $json = ProviderHttp::json($response);
        if ($status !== 200 || empty($json['ok'])) {
            throw new RuntimeException('Telegram refused the request (HTTP ' . $status . '): ' . ($json['description'] ?? 'no reason given'));
        }
        $chats = [];
        foreach ((array) $json['result'] as $update) {
            foreach (['message', 'channel_post', 'my_chat_member', 'edited_message'] as $kind) {
                $chat = $update[$kind]['chat'] ?? null;
                if (is_array($chat) && isset($chat['id'])) {
                    $name = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) ?: ($chat['username'] ?? '');
                    $chats[(string) $chat['id']] = $chat['id'] . ' · ' . $name . ' (' . ($chat['type'] ?? 'chat') . ')';
                }
            }
        }
        return array_values($chats);
    }

    /** @return array{active:array<string,array{text:string,since:int}>,pending:array<string,int>,log:list<array>,report_date?:string} */
    private static function state(): array
    {
        $state = json_decode(Settings::value(self::STATE, ''), true);
        $state = is_array($state) ? $state : [];
        return ['active' => (array) ($state['active'] ?? []), 'pending' => (array) ($state['pending'] ?? []), 'log' => array_values((array) ($state['log'] ?? []))] + array_intersect_key($state, ['report_date' => true]);
    }

    private static function saveState(array $state): void
    {
        Settings::save(self::STATE, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function log(array &$state, int $at, string $kind, string $text, string $sent): void
    {
        array_unshift($state['log'], ['at' => $at, 'kind' => $kind, 'text' => mb_substr($text, 0, 300), 'sent' => mb_substr($sent, 0, 500)]);
        $state['log'] = array_slice($state['log'], 0, self::LOG_SIZE);
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n]+/', $value) ?: []), static fn ($v) => $v !== '')));
    }

    private static function appName(): string
    {
        return Branding::name();
    }
}
