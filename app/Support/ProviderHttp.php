<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * HTTPS calls to known providers (Twilio, Infobip, Mailgun, Microsoft Graph, Google Drive, S3) with the
 * same protections as custom gateways (HttpGateway::destination): only the provider's own hosts, public
 * addresses pinned for the request, no redirects, no proxy, a bounded response. Unlike HttpGateway::request
 * every status is returned, because each provider reports success and errors differently.
 */
final class ProviderHttp
{
    /** @var (\Closure(string, string, array, string|array|null): array)|null */
    private static ?\Closure $fake = null;

    /** Tests only: answer requests in-process (host approval is still checked, without DNS). */
    public static function fake(?\Closure $handler): void
    {
        self::$fake = $handler;
    }

    /**
     * @param  list<string>  $hosts  approved hosts for this provider ("*.example.com" for account subdomains)
     * @param  array<string, string>  $headers
     * @param  string|array{file: string}|null  $body  raw body, or a file to upload (streamed, never read into memory)
     * @return array{0: int, 1: string, 2: array<string, string>} status, body, lower-cased response headers
     */
    public static function request(string $method, string $url, array $hosts, array $headers = [], string|array|null $body = null, int $timeout = 20, int $maxResponse = 1048576): array
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('Provider URLs must use HTTPS.');
        }
        if (self::$fake !== null) {
            HttpGateway::destination($url, static fn () => ['93.184.216.34'], $hosts);
            return (self::$fake)($method, $url, $headers, $body) + [2 => []];
        }
        [$host, $port, $ip] = HttpGateway::destination($url, null, $hosts);
        $response = '';
        $responseHeaders = [];
        $file = null;
        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, $maxResponse): int {
                if (strlen($response) + strlen($chunk) > $maxResponse) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if (is_array($body)) {
            $file = fopen($body['file'], 'rb');
            if ($file === false) {
                throw new RuntimeException('Upload file is not readable.');
            }
            $options += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $file, CURLOPT_INFILESIZE => (int) filesize($body['file'])];
        } elseif ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);
        try {
            $ok = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $errno = curl_errno($curl);
            if ($ok === false) {
                if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
                    throw new NotificationRetry('Provider connection unavailable.');
                }
                throw new RuntimeException('Provider request did not complete (cURL error ' . $errno . ').');
            }
            return [$code, $response, $responseHeaders];
        } finally {
            curl_close($curl);
            if ($file !== null) {
                fclose($file);
            }
        }
    }

    /** JSON response body as an array (empty when the body is not JSON). */
    public static function json(string $body): array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
