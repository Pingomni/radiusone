<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class NotificationService
{
    private static ?array $settingsCache = null;

    /** One bounded job/request snapshot; never persists between jobs or hides DB failures. */
    public static function withSettings(\Closure $work): mixed
    {
        if (self::$settingsCache !== null) {
            return $work();
        }
        self::$settingsCache = [];
        try {
            foreach (Db::select('SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE "notification\_%" OR setting_key LIKE "sms\_%" OR setting_key LIKE "email\_%"') as $row) {
                self::$settingsCache[$row['setting_key']] = Secrets::reveal($row['setting_value']);
            }
            return $work();
        } finally {
            self::$settingsCache = null;
        }
    }

    public static function generalSettings(): array
    {
        return [
            'enabled' => self::setting('notification_expiry_enabled', '0'),
            'days_before' => self::setting('notification_expiry_days_before', '3'),
            'send_time' => self::setting('notification_expiry_send_time', '09:00'),
            'sms_enabled' => self::setting('notification_expiry_sms_enabled', '1'),
            'email_enabled' => self::setting('notification_expiry_email_enabled', '1'),
            'respect_client_flags' => self::setting('notification_respect_client_flags', '1'),
        ];
    }

    public static function smsGatewaySettings(): array
    {
        return [
            'enabled' => self::setting('sms_gateway_enabled', '0'),
            'provider' => self::setting('sms_gateway_provider', 'custom') ?: 'custom',
            'country_code' => self::setting('sms_gateway_country_code', ''),
            'method' => self::setting('sms_gateway_method', 'GET') ?: 'GET',
            'url' => self::setting('sms_gateway_url', ''),
            'body' => self::setting('sms_gateway_body', ''),
            'success' => self::setting('sms_gateway_success', ''),
            'twilio_sid' => self::setting('sms_gateway_twilio_sid', ''),
            'twilio_token' => self::setting('sms_gateway_twilio_token', ''),
            'twilio_from' => self::setting('sms_gateway_twilio_from', ''),
            'infobip_base_url' => self::setting('sms_gateway_infobip_base_url', ''),
            'infobip_api_key' => self::setting('sms_gateway_infobip_api_key', ''),
            'infobip_from' => self::setting('sms_gateway_infobip_from', ''),
        ];
    }

    public static function emailGatewaySettings(): array
    {
        return [
            'enabled' => self::setting('email_gateway_enabled', '0'),
            // A retired provider (Google, Microsoft 365) reads as custom SMTP.
            'provider' => isset(EmailProviders::PROVIDERS[self::setting('email_gateway_provider', 'smtp')]) ? self::setting('email_gateway_provider', 'smtp') : 'smtp',
            'transport' => self::setting('email_gateway_transport', 'smtp'),
            'host' => self::setting('email_gateway_host', ''),
            'port' => self::setting('email_gateway_port', '587'),
            'encryption' => self::setting('email_gateway_encryption', 'tls'),
            'username' => self::setting('email_gateway_username', ''),
            'password' => self::setting('email_gateway_password', ''),
            'from_email' => self::setting('email_gateway_from_email', ''),
            'from_name' => self::setting('email_gateway_from_name', Branding::name()),
            'reply_to' => self::setting('email_gateway_reply_to', ''),
            'timeout' => self::setting('email_gateway_timeout', '12'),
            'mailgun_domain' => self::setting('email_gateway_mailgun_domain', ''),
            'mailgun_api_key' => self::setting('email_gateway_mailgun_api_key', ''),
            'mailgun_region' => self::setting('email_gateway_mailgun_region', 'us') ?: 'us',
        ];
    }

    /**
     * Settings for the browser: credentials are replaced by whether one is saved.
     *
     * @param  list<string>  $secrets
     */
    public static function withoutSecrets(array $settings, array $secrets): array
    {
        foreach ($secrets as $key) {
            $settings[$key . '_saved'] = ($settings[$key] ?? '') !== '';
            unset($settings[$key]);
        }
        return $settings;
    }

    public static function templates(): array
    {
        return [
            'sms_expiry_body' => self::setting('sms_expiry_body', 'Hello {name}, your internet service expires on {expiry}. Please renew before {expiry} to avoid interruption.'),
            'email_expiry_subject' => self::setting('email_expiry_subject', 'Your internet service expires on {expiry}'),
            'email_expiry_body' => self::setting('email_expiry_body', "Hello {name},\n\nYour internet service ({service}) expires on {expiry}.\n\nPlease renew before {expiry} to avoid interruption.\n\nThank you."),
        ];
    }

    public static function saveGeneral(array $input): void
    {
        $days = max(0, min(365, (int) ($input['notification_expiry_days_before'] ?? 3)));
        $time = trim((string) ($input['notification_expiry_send_time'] ?? '09:00'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            $time = '09:00';
        }

        self::saveSetting('notification_expiry_enabled', isset($input['notification_expiry_enabled']) ? '1' : '0');
        self::saveSetting('notification_expiry_days_before', (string) $days);
        self::saveSetting('notification_expiry_send_time', $time);
        self::saveSetting('notification_expiry_sms_enabled', isset($input['notification_expiry_sms_enabled']) ? '1' : '0');
        self::saveSetting('notification_expiry_email_enabled', isset($input['notification_expiry_email_enabled']) ? '1' : '0');
        self::saveSetting('notification_respect_client_flags', isset($input['notification_respect_client_flags']) ? '1' : '0');
    }

    public static function saveSmsGateway(array $input): void
    {
        $provider = (string) ($input['sms_gateway_provider'] ?? 'custom');
        if (!isset(SmsProviders::PROVIDERS[$provider])) {
            $provider = 'custom';
        }
        $method = strtoupper((string) ($input['sms_gateway_method'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST'], true)) {
            $method = 'GET';
        }
        $current = self::smsGatewaySettings();
        $values = [
            'enabled' => isset($input['sms_gateway_enabled']) ? '1' : '0',
            'provider' => $provider,
            'country_code' => trim((string) ($input['sms_gateway_country_code'] ?? '')),
            'method' => $method,
            'url' => trim((string) ($input['sms_gateway_url'] ?? '')),
            'body' => trim((string) ($input['sms_gateway_body'] ?? '')),
            'success' => trim((string) ($input['sms_gateway_success'] ?? '')),
            'twilio_sid' => trim((string) ($input['sms_gateway_twilio_sid'] ?? '')),
            'twilio_from' => trim((string) ($input['sms_gateway_twilio_from'] ?? '')),
            'infobip_base_url' => trim((string) ($input['sms_gateway_infobip_base_url'] ?? '')),
            'infobip_from' => trim((string) ($input['sms_gateway_infobip_from'] ?? '')),
        ];
        foreach (SmsProviders::SECRETS as $key) {
            $typed = trim((string) ($input['sms_gateway_' . $key] ?? ''));
            $values[$key] = $typed !== '' ? $typed : $current[$key];
        }
        if ($values['enabled'] === '1') {
            if ($provider === 'custom') {
                self::validateCustomSms($values);
            }
            SmsProviders::validate($values);
        }
        foreach ($values as $key => $value) {
            self::saveSetting('sms_gateway_' . $key, $value, in_array($key, ['url', 'body', ...SmsProviders::SECRETS], true));
        }
    }

    private static function validateCustomSms(array $values): void
    {
        $url = $values['url'];
        $body = $values['method'] === 'POST' ? $values['body'] : '';
        if (!preg_match('#^https?://#i', $url) || !str_contains($url . $body, '{phone}') || !str_contains($url . $body, '{message}')) {
            throw new \InvalidArgumentException('Enter a gateway URL and include {phone} and {message} in the URL or POST body.');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_map('trim', explode(',', strtolower((string) App::env('NOTIFICATION_HTTP_ALLOWED_HOSTS', ''))));
        if (!in_array($host, $allowed, true)) {
            throw new \InvalidArgumentException('This SMS provider host needs administrator approval in NOTIFICATION_HTTP_ALLOWED_HOSTS before it can be enabled.');
        }
        $parts = parse_url($url);
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array($parts['port'] ?? (str_starts_with($url, 'https:') ? 443 : 80), [80, 443], true)) {
            throw new \InvalidArgumentException('Use an HTTP or HTTPS gateway on port 80 or 443 without URL credentials or a fragment.');
        }
        Security::assertHttpUrl($url);
    }

    public static function saveEmailGateway(array $input): void
    {
        $provider = (string) ($input['email_gateway_provider'] ?? 'smtp');
        if (!isset(EmailProviders::PROVIDERS[$provider])) {
            $provider = 'smtp';
        }
        $transport = (string) ($input['email_gateway_transport'] ?? 'smtp');
        $encryption = (string) ($input['email_gateway_encryption'] ?? 'tls');
        $region = (string) ($input['email_gateway_mailgun_region'] ?? 'us');
        $current = self::emailGatewaySettings();
        $values = [
            'enabled' => isset($input['email_gateway_enabled']) ? '1' : '0',
            'provider' => $provider,
            'transport' => in_array($transport, ['smtp', 'mail'], true) ? $transport : 'smtp',
            'host' => trim((string) ($input['email_gateway_host'] ?? '')),
            'port' => (string) max(1, min(65535, (int) ($input['email_gateway_port'] ?? 587))),
            'encryption' => in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
            'username' => trim((string) ($input['email_gateway_username'] ?? '')),
            'from_email' => trim((string) ($input['email_gateway_from_email'] ?? '')),
            'from_name' => trim((string) ($input['email_gateway_from_name'] ?? '')),
            'reply_to' => trim((string) ($input['email_gateway_reply_to'] ?? '')),
            'timeout' => (string) max(3, min(60, (int) ($input['email_gateway_timeout'] ?? 12))),
            'mailgun_domain' => strtolower(trim((string) ($input['email_gateway_mailgun_domain'] ?? ''))),
            'mailgun_region' => in_array($region, ['us', 'eu'], true) ? $region : 'us',
        ];
        foreach (EmailProviders::SECRETS as $key) {
            // Passwords are never trimmed; blank keeps the saved credential.
            $typed = (string) ($input['email_gateway_' . $key] ?? '');
            $values[$key] = $typed !== '' ? $typed : $current[$key];
        }
        if ($values['enabled'] === '1') {
            EmailProviders::validate($values);
        }
        foreach ($values as $key => $value) {
            self::saveSetting('email_gateway_' . $key, $value, in_array($key, ['username', ...EmailProviders::SECRETS], true));
        }
    }

    public static function saveTemplates(array $input): void
    {
        self::saveSetting('sms_expiry_body', trim((string) ($input['sms_expiry_body'] ?? '')));
        self::saveSetting('email_expiry_subject', trim((string) ($input['email_expiry_subject'] ?? '')));
        self::saveSetting('email_expiry_body', trim((string) ($input['email_expiry_body'] ?? '')));
    }

    public static function saveSmsTemplate(array $input): void
    {
        self::saveSetting('sms_expiry_body', trim((string) ($input['sms_expiry_body'] ?? '')));
    }

    public static function saveEmailTemplate(array $input): void
    {
        self::saveSetting('email_expiry_subject', trim((string) ($input['email_expiry_subject'] ?? '')));
        self::saveSetting('email_expiry_body', trim((string) ($input['email_expiry_body'] ?? '')));
    }

    public static function expiringClients(): array
    {
        $general = self::generalSettings();
        $days = max(0, (int) $general['days_before']);
        $target = date('Y-m-d', strtotime('+' . $days . ' days'));
        return Db::select(
            'SELECT c.username, c.first_name, c.last_name, c.company_name, c.phone, c.cell_phone, c.email,
                    c.email_alert, c.sms_alert, c.service_group, c.expiry_date, s.name AS service_name
             FROM customer_profiles c
             LEFT JOIN service_metadata s ON s.group_name = c.service_group
             WHERE c.enabled = 1 AND c.expiry_date = ?
             ORDER BY c.expiry_date, c.username',
            [$target]
        );
    }

    public static function processExpiryNotifications(bool $force = false): array
    {
        return self::withSettings(static fn () => NotificationQueue::process(self::generalSettings()));
    }

    public static function sendSms(array $client, string $phone, string $message, bool $literal = false): void
    {
        $settings = self::smsGatewaySettings();
        if ($settings['enabled'] !== '1') {
            throw new RuntimeException('SMS gateway disabled');
        }
        $text = $literal ? $message : self::applyTemplate($message, $client, ['phone' => $phone], false);
        if ($settings['provider'] !== 'custom') {
            SmsProviders::send($settings, $phone, $text);
            return;
        }
        if (trim($settings['url']) === '') {
            throw new RuntimeException('SMS gateway URL missing');
        }

        $vars = self::placeholderValues($client, ['phone' => $phone, 'message' => $text]);
        $url = self::replacePlaceholders($settings['url'], $vars);
        $body = self::replacePlaceholders($settings['body'], $vars);
        $method = strtoupper($settings['method']) === 'POST' ? 'POST' : 'GET';
        [$httpCode,$response] = HttpGateway::request($url, $method, $body);
        $success = trim($settings['success']);
        if ($success !== '' && !str_contains($response, $success)) {
            throw new RuntimeException('gateway response did not contain success text');
        }
    }

    public static function sendEmail(array $client, string $subjectTemplate, string $bodyTemplate, bool $literal = false): void
    {
        $settings = self::emailGatewaySettings();
        if ($settings['enabled'] !== '1') {
            throw new RuntimeException('Email gateway disabled');
        }
        $email = trim((string) ($client['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('missing email');
        }
        $from = trim((string) $settings['from_email']);
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('from email missing');
        }
        if (trim((string) $settings['reply_to']) !== '' && !filter_var(trim((string) $settings['reply_to']), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('reply-to email invalid');
        }

        $subject = $literal ? $subjectTemplate : self::applyTemplate($subjectTemplate, $client, [], false);
        $body = $literal ? $bodyTemplate : self::applyTemplate($bodyTemplate, $client, [], false);
        EmailProviders::send($settings, $email, $subject, $body);
    }

    public static function applyTemplate(string $template, array $client, array $extra = [], bool $encode = true): string
    {
        return self::replacePlaceholders($template, self::placeholderValues($client, $extra), $encode);
    }

    public static function replacePlaceholders(string $template, array $values, bool $encode = true): string
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map['{' . $key . '}'] = $encode ? rawurlencode((string) $value) : (string) $value;
        }
        return strtr($template, $map);
    }

    public static function sendViaMail(string $to, string $subject, string $body, array $settings): void
    {
        $headers = [
            'From: ' . self::mailbox((string) $settings['from_email'], (string) $settings['from_name']),
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if (trim((string) $settings['reply_to']) !== '') {
            $headers[] = 'Reply-To: ' . trim((string) $settings['reply_to']);
        }
        if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('mail() failed');
        }
    }

    public static function sendViaSmtp(string $to, string $subject, string $body, array $settings): void
    {
        $host = trim((string) $settings['host']);
        if ($host === '') {
            throw new RuntimeException('SMTP host missing');
        }
        $port = (int) $settings['port'];
        $timeout = (int) $settings['timeout'];
        $remote = ($settings['encryption'] === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new RuntimeException('SMTP connect failed: ' . ($errstr ?: $errno));
        }
        stream_set_timeout($socket, $timeout);

        try {
            self::smtpExpect($socket, [220]);
            self::smtpCommand($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
            if ($settings['encryption'] === 'tls') {
                self::smtpCommand($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS failed');
                }
                self::smtpCommand($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
            }
            if (trim((string) $settings['username']) !== '') {
                self::smtpCommand($socket, 'AUTH LOGIN', [334]);
                self::smtpCommand($socket, base64_encode((string) $settings['username']), [334]);
                self::smtpCommand($socket, base64_encode((string) $settings['password']), [235]);
            }

            $from = (string) $settings['from_email'];
            self::smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::smtpCommand($socket, 'DATA', [354]);
            $headers = [
                'From: ' . self::mailbox($from, (string) $settings['from_name']),
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date(DATE_RFC2822),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
            ];
            if (trim((string) $settings['reply_to']) !== '') {
                $headers[] = 'Reply-To: ' . trim((string) $settings['reply_to']);
            }
            $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", str_replace("\n", "\r\n", $body)) . "\r\n.";
            self::smtpCommand($socket, $data, [250]);
            self::smtpCommand($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private static function smtpCommand($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return self::smtpExpect($socket, $expected);
    }

    private static function smtpExpect($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new RuntimeException('SMTP no response');
            }
            $response .= $line;
            $code = (int) substr($line, 0, 3);
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
        return $response;
    }

    public static function mailbox(string $email, string $name): string
    {
        $email = trim($email);
        $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('email address invalid');
        }
        return $name !== '' ? self::encodeHeader($name) . ' <' . $email . '>' : '<' . $email . '>';
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : str_replace(["\r", "\n"], '', $value);
    }

    private static function placeholderValues(array $client, array $extra = []): array
    {
        $name = trim((string) (($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')));
        if ($name === '') {
            $name = (string) (($client['company_name'] ?? '') ?: ($client['username'] ?? ''));
        }
        return array_merge([
            'notification_id' => (string) ($client['notification_id'] ?? ''),
            'username' => (string) ($client['username'] ?? ''),
            'name' => $name,
            'phone' => (string) (($client['cell_phone'] ?? '') ?: ($client['phone'] ?? '')),
            'email' => (string) ($client['email'] ?? ''),
            'service' => (string) (($client['service_name'] ?? '') ?: ($client['service_group'] ?? '')),
            'expiry' => (string) ($client['expiry_date'] ?? ''),
            'days_before' => self::setting('notification_expiry_days_before', '3'),
            'app_name' => Branding::name(),
        ], $extra);
    }

    private static function setting(string $key, string $default = ''): string
    {
        if (self::$settingsCache !== null) {
            return (string) (self::$settingsCache[$key] ?? $default);
        }
        try {
            $value = Db::value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        } catch (\Throwable) {
            return $default;
        }
        return $value === false || $value === null ? $default : Secrets::reveal((string) $value);
    }

    private static function saveSetting(string $key, string $value, bool $secret = false): void
    {
        $user = Auth::user();
        Db::exec(
            'INSERT INTO settings (setting_key, setting_value, is_secret, updated_by, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), is_secret=VALUES(is_secret), updated_by=VALUES(updated_by), updated_at=NOW()',
            [$key, $secret ? Secrets::encrypt($value) : $value, $secret ? 1 : 0, $user['id'] ?? null]
        );
        if (self::$settingsCache !== null) {
            self::$settingsCache[$key] = $value;
        }
    }
}
