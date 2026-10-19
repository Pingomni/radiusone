<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Email delivery per provider, chosen under Settings → Notifications → Email: custom SMTP (or PHP
 * mail()) or Mailgun (HTTP API).
 * Every method throws when the provider did not accept the message.
 */
final class EmailProviders
{
    public const PROVIDERS = ['smtp' => 'Custom SMTP', 'mailgun' => 'Mailgun'];

    /** Settings keys (without the email_gateway_ prefix) that hold credentials. */
    public const SECRETS = ['password', 'mailgun_api_key'];

    /** @param array<string, string> $s NotificationService::emailGatewaySettings() */
    public static function send(array $s, string $to, string $subject, string $body): void
    {
        match ($s['provider']) {
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
        if ($s['provider'] === 'mailgun' && (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $s['mailgun_domain']) || $s['mailgun_api_key'] === '' || !in_array($s['mailgun_region'], ['us', 'eu'], true))) {
            throw new \InvalidArgumentException('Enter your Mailgun sending domain, region and API key.');
        }
        if ($s['provider'] === 'smtp' && $s['transport'] !== 'mail' && trim($s['host']) === '') {
            throw new \InvalidArgumentException('Enter your SMTP host.');
        }
    }

    private static function smtp(array $s, string $to, string $subject, string $body): void
    {
        if ($s['transport'] === 'mail') {
            NotificationService::sendViaMail($to, $subject, $body, $s);
            return;
        }
        NotificationService::sendViaSmtp($to, $subject, $body, $s);
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
