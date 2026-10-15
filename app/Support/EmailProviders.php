<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Email delivery per provider, chosen under Settings → Notifications → Email:
 * custom SMTP (or PHP mail()), Google (Gmail SMTP with an app password), Microsoft 365 (Graph API with
 * an app registration: Microsoft is retiring password SMTP for Exchange Online) and Mailgun (HTTP API).
 * Every method throws when the provider did not accept the message.
 */
final class EmailProviders
{
    public const PROVIDERS = ['smtp' => 'Custom SMTP', 'google' => 'Google (Gmail / Workspace)', 'microsoft' => 'Microsoft 365', 'mailgun' => 'Mailgun'];

    /** Settings keys (without the email_gateway_ prefix) that hold credentials. */
    public const SECRETS = ['password', 'ms_client_secret', 'mailgun_api_key'];

    private const GOOGLE_SMTP = ['host' => 'smtp.gmail.com', 'port' => '587', 'encryption' => 'tls'];

    /** @param array<string, string> $s NotificationService::emailGatewaySettings() */
    public static function send(array $s, string $to, string $subject, string $body): void
    {
        match ($s['provider']) {
            'google' => self::smtp(array_replace($s, self::GOOGLE_SMTP), $to, $subject, $body),
            'microsoft' => self::microsoft($s, $to, $subject, $body),
            'mailgun' => self::mailgun($s, $to, $subject, $body),
            default => self::smtp($s, $to, $subject, $body),
        };
    }

    /** Whether the saved settings are complete enough to send. */
    public static function ready(array $s): bool
    {
        if ($s['enabled'] !== '1' || !filter_var($s['from_email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return match ($s['provider']) {
            'google' => $s['username'] !== '' && $s['password'] !== '',
            'microsoft' => $s['ms_tenant_id'] !== '' && $s['ms_client_id'] !== '' && $s['ms_client_secret'] !== '',
            'mailgun' => $s['mailgun_domain'] !== '' && $s['mailgun_api_key'] !== '',
            default => $s['transport'] === 'mail' || trim($s['host']) !== '',
        };
    }

    /** Validation for an enabled gateway; $s already has secrets merged (blank input keeps the saved one). */
    public static function validate(array $s): void
    {
        if (!filter_var($s['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid sender email address.');
        }
        if ($s['reply_to'] !== '' && !filter_var($s['reply_to'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid reply-to address.');
        }
        if ($s['provider'] === 'google' && (!filter_var($s['username'], FILTER_VALIDATE_EMAIL) || $s['password'] === '')) {
            throw new \InvalidArgumentException('Enter the Google account address and an app password (Google Account → Security → App passwords).');
        }
        if ($s['provider'] === 'microsoft' && (!preg_match('/^[A-Za-z0-9.-]{3,253}$/D', $s['ms_tenant_id']) || !preg_match('/^[0-9a-fA-F-]{36}$/D', $s['ms_client_id']) || $s['ms_client_secret'] === '')) {
            throw new \InvalidArgumentException('Enter the tenant ID (or domain), the application (client) ID and a client secret from the Azure app registration.');
        }
        if ($s['provider'] === 'mailgun' && (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $s['mailgun_domain']) || $s['mailgun_api_key'] === '' || !in_array($s['mailgun_region'], ['us', 'eu'], true))) {
            throw new \InvalidArgumentException('Enter your Mailgun sending domain, region and API key.');
        }
        if ($s['provider'] === 'smtp' && $s['transport'] !== 'mail' && trim($s['host']) === '') {
            throw new \InvalidArgumentException('Enter your SMTP host.');
        }
    }

    private static function smtp(array $s, string $to, string $subject, string $body): void
    {
        if ($s['transport'] === 'mail' && $s['provider'] === 'smtp') {
            NotificationService::sendViaMail($to, $subject, $body, $s);
            return;
        }
        NotificationService::sendViaSmtp($to, $subject, $body, $s);
    }

    private static function microsoft(array $s, string $to, string $subject, string $body): void
    {
        $tenant = rawurlencode($s['ms_tenant_id']);
        [$status, $response] = ProviderHttp::request('POST', 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token', ['login.microsoftonline.com'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $s['ms_client_id'], 'client_secret' => $s['ms_client_secret'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']),
            (int) $s['timeout']);
        $token = (string) (ProviderHttp::json($response)['access_token'] ?? '');
        if ($status !== 200 || $token === '') {
            throw new RuntimeException('Microsoft sign-in failed: ' . self::detail($response, 'error_description'));
        }
        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
        ];
        if ($s['reply_to'] !== '') {
            $message['replyTo'] = [['emailAddress' => ['address' => $s['reply_to']]]];
        }
        [$status, $response] = ProviderHttp::request('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($s['from_email']) . '/sendMail', ['graph.microsoft.com'],
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['message' => $message, 'saveToSentItems' => false], JSON_THROW_ON_ERROR), (int) $s['timeout']);
        if ($status !== 202) {
            throw new RuntimeException('Microsoft 365 did not accept the message (HTTP ' . $status . '): ' . self::detail($response, 'error.message'));
        }
    }

    private static function mailgun(array $s, string $to, string $subject, string $body): void
    {
        $host = $s['mailgun_region'] === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
        $fields = ['from' => NotificationService::mailbox($s['from_email'], $s['from_name']), 'to' => $to, 'subject' => $subject, 'text' => $body];
        if ($s['reply_to'] !== '') {
            $fields['h:Reply-To'] = $s['reply_to'];
        }
        [$status, $response] = ProviderHttp::request('POST', 'https://' . $host . '/v3/' . rawurlencode($s['mailgun_domain']) . '/messages', [$host],
            ['Authorization' => 'Basic ' . base64_encode('api:' . $s['mailgun_api_key']), 'Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields), (int) $s['timeout']);
        if ($status !== 200 || empty(ProviderHttp::json($response)['id'])) {
            throw new RuntimeException('Mailgun did not accept the message (HTTP ' . $status . '): ' . self::detail($response, 'message'));
        }
    }

    /** A short provider error for the operator (never the request, which holds credentials). */
    public static function detail(string $response, string $path): string
    {
        $value = ProviderHttp::json($response);
        foreach (explode('.', $path) as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        $text = is_string($value) && $value !== '' ? $value : trim(strip_tags($response));
        return mb_substr($text !== '' ? $text : 'no details', 0, 200);
    }
}
