<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * SMS delivery per provider, chosen under Settings → Notifications → SMS: a custom HTTP template
 * (any provider; its host must be approved in NOTIFICATION_HTTP_ALLOWED_HOSTS), Twilio or Infobip
 * (their API hosts are built in). Every method throws when the provider did not accept the message.
 */
final class SmsProviders
{
    public const PROVIDERS = ['custom' => 'Custom HTTP', 'twilio' => 'Twilio', 'infobip' => 'Infobip'];

    /** Settings keys (without the sms_gateway_ prefix) that hold credentials. */
    public const SECRETS = ['twilio_token', 'infobip_api_key'];

    /** @param array<string, string> $s NotificationService::smsGatewaySettings() */
    public static function send(array $s, string $phone, string $message): void
    {
        $to = self::international($phone, $s['country_code']);
        match ($s['provider']) {
            'twilio' => self::twilio($s, $to, $message),
            'infobip' => self::infobip($s, $to, $message),
            default => throw new \LogicException('Custom HTTP is sent by NotificationService::sendSms.'),
        };
    }

    public static function ready(array $s): bool
    {
        if ($s['enabled'] !== '1') {
            return false;
        }
        return match ($s['provider']) {
            'twilio' => $s['twilio_sid'] !== '' && $s['twilio_token'] !== '' && $s['twilio_from'] !== '',
            'infobip' => self::infobipHost($s['infobip_base_url']) !== null && $s['infobip_api_key'] !== '' && $s['infobip_from'] !== '',
            default => self::customReady($s),
        };
    }

    public static function validate(array $s): void
    {
        if ($s['country_code'] !== '' && !preg_match('/^[1-9][0-9]{0,3}$/D', $s['country_code'])) {
            throw new \InvalidArgumentException('Enter the default country code as digits only, for example 355.');
        }
        if ($s['provider'] === 'twilio' && (!preg_match('/^AC[0-9a-fA-F]{32}$/D', $s['twilio_sid']) || $s['twilio_token'] === '' || !preg_match('/^(\+[1-9][0-9]{6,14}|MG[0-9a-fA-F]{32}|[A-Za-z0-9 ]{1,11})$/D', $s['twilio_from']))) {
            throw new \InvalidArgumentException('Enter the Twilio Account SID (AC…), auth token, and a sender: a +number, a Messaging Service SID (MG…) or an alphanumeric sender ID.');
        }
        if ($s['provider'] === 'infobip' && (self::infobipHost($s['infobip_base_url']) === null || $s['infobip_api_key'] === '' || trim($s['infobip_from']) === '')) {
            throw new \InvalidArgumentException('Enter your Infobip base URL (xxxxx.api.infobip.com), API key and sender.');
        }
    }

    /** Local numbers ("069…", "69…") become +<country code>…; "00…" becomes "+…". */
    public static function international(string $phone, string $countryCode): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if (str_starts_with($digits, '+')) {
            return '+' . ltrim(substr($digits, 1), '+');
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }
        if ($countryCode === '') {
            return '+' . $digits;
        }
        if (str_starts_with($digits, $countryCode) && strlen($digits) > strlen($countryCode) + 6) {
            return '+' . $digits;
        }
        return '+' . $countryCode . ltrim($digits, '0');
    }

    public static function infobipHost(string $baseUrl): ?string
    {
        $host = strtolower((string) (parse_url(str_contains($baseUrl, '://') ? $baseUrl : 'https://' . $baseUrl, PHP_URL_HOST) ?? ''));
        return preg_match('/^[a-z0-9-]+\.api\.infobip\.com$/D', $host) ? $host : null;
    }

    private static function customReady(array $s): bool
    {
        $payload = $s['url'] . ($s['method'] === 'POST' ? $s['body'] : '');
        $host = strtolower((string) parse_url($s['url'], PHP_URL_HOST));
        $allowed = array_map('trim', explode(',', strtolower((string) App::env('NOTIFICATION_HTTP_ALLOWED_HOSTS', ''))));
        return in_array($host, $allowed, true) && (bool) preg_match('#^https?://#i', $s['url']) && str_contains($payload, '{phone}') && str_contains($payload, '{message}');
    }

    private static function twilio(array $s, string $to, string $message): void
    {
        $fields = ['To' => $to, 'Body' => $message];
        $fields[str_starts_with($s['twilio_from'], 'MG') ? 'MessagingServiceSid' : 'From'] = $s['twilio_from'];
        [$status, $response] = ProviderHttp::request('POST', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($s['twilio_sid']) . '/Messages.json', ['api.twilio.com'],
            ['Authorization' => 'Basic ' . base64_encode($s['twilio_sid'] . ':' . $s['twilio_token']), 'Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields), 15);
        if (!in_array($status, [200, 201], true) || empty(ProviderHttp::json($response)['sid'])) {
            throw new RuntimeException('Twilio did not accept the message (HTTP ' . $status . '): ' . EmailProviders::detail($response, 'message'));
        }
    }

    private static function infobip(array $s, string $to, string $message): void
    {
        $host = self::infobipHost($s['infobip_base_url']) ?? throw new RuntimeException('Infobip base URL is invalid.');
        $payload = ['messages' => [['destinations' => [['to' => ltrim($to, '+')]], 'from' => $s['infobip_from'], 'text' => $message]]];
        [$status, $response] = ProviderHttp::request('POST', 'https://' . $host . '/sms/2/text/advanced', ['*.api.infobip.com'],
            ['Authorization' => 'App ' . $s['infobip_api_key'], 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR), 15);
        $group = (string) (ProviderHttp::json($response)['messages'][0]['status']['groupName'] ?? '');
        if ($status !== 200 || !in_array($group, ['PENDING', 'DELIVERED'], true)) {
            throw new RuntimeException('Infobip did not accept the message (HTTP ' . $status . ($group !== '' ? ', ' . $group : '') . '): '
                . EmailProviders::detail($response, $group !== '' ? 'messages.0.status.description' : 'requestError.serviceException.text'));
        }
    }
}
