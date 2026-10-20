<?php

declare(strict_types=1);

namespace App\Support;

final class HttpGateway
{
    /**
     * @param  list<string>|null  $hosts  approved hosts ("*.example.com" matches any subdomain); null uses
     *                                    NOTIFICATION_HTTP_ALLOWED_HOSTS, the administrator's list for custom gateways
     */
    public static function destination(string $url, ?\Closure $resolve = null, ?array $hosts = null): array
    {
        Security::assertHttpUrl($url);
        $p = parse_url($url);
        $host = strtolower($p['host']);
        $allowed = $hosts ?? array_filter(array_map('trim', explode(',', strtolower((string) App::env('NOTIFICATION_HTTP_ALLOWED_HOSTS', '')))));
        $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
        if (!self::approved($host, $allowed) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || !in_array($port, [80, 443], true)) {
            throw new \RuntimeException('Gateway destination is not approved.');
        }
        $resolve ??= static function ($host) {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return [$host];
            }
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $ips = [];
            foreach ($records ?: [] as $row) {
                $ips[] = $row['ip'] ?? $row['ipv6'] ?? '';
            }
            return $ips;
        };
        $ips = $resolve($host);
        if (!$ips) {
            throw new NotificationRetry('Gateway DNS unavailable.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || (str_contains($ip, ':') && (ord(inet_pton($ip)[0]) & 0xE0) !== 0x20)) {
                throw new \RuntimeException('Gateway address is not public.');
            }
        }
        return [$host, $port, $ips[0]];
    }
    /** @param list<string> $allowed */
    public static function approved(string $host, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === $host || (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1)) && strlen($host) > strlen($entry) - 1)) {
                return true;
            }
        }
        return false;
    }

    public static function request(string $url, string $method, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('SMS delivery requires PHP cURL; no alternate redirect policy is used.');
        }
        [$host,$port,$ip] = self::destination($url);
        $response = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 65536) {
                    return 0;
                }$response .= $chunk;
                return strlen($chunk);
            }]);
        if ($method === 'POST') {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
        }
        try {
            $ok = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $errno = curl_errno($curl);
            if ($ok === false) {
                if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
                    throw new NotificationRetry('Gateway connection unavailable.');
                }
                throw new \RuntimeException('Gateway outcome uncertain.');
            }
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('Gateway did not confirm delivery.');
            }
            return [$code, $response];
        } finally {
            curl_close($curl);
        }
    }
}
