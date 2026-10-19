<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\ProviderHttp;
use RuntimeException;

/**
 * S3-compatible object storage (Backblaze B2, Cloudflare R2, Wasabi, Hetzner, DigitalOcean Spaces…)
 * with Signature V4 and path-style URLs on the provider's endpoint. The key needs to write, list
 * and delete objects in the bucket.
 */
final class S3Target implements BackupTarget
{
    public function __construct(private readonly array $c)
    {
    }

    /** Providers with a known endpoint pattern; "region" is what their Signature V4 scope expects. */
    public const PROVIDERS = [
        'b2' => 'Backblaze B2',
        'r2' => 'Cloudflare R2',
        'wasabi' => 'Wasabi',
        'hetzner' => 'Hetzner Object Storage',
        'spaces' => 'DigitalOcean Spaces',
    ];

    /** Each provider's endpoints live under its own domain. */
    private const DOMAINS = [
        'b2' => '.backblazeb2.com',
        'r2' => '.r2.cloudflarestorage.com',
        'wasabi' => '.wasabisys.com',
        'hetzner' => '.your-objectstorage.com',
        'spaces' => '.digitaloceanspaces.com',
    ];

    /** Each provider's endpoint host (without the bucket), as a regular expression. */
    private const ENDPOINTS = [
        'b2' => 's3\.[a-z0-9-]+\.backblazeb2\.com',
        'r2' => '[a-f0-9]{32}(?:\.eu|\.fedramp)?\.r2\.cloudflarestorage\.com',
        'wasabi' => 's3(?:\.[a-z0-9-]+)?\.wasabisys\.com',
        'hetzner' => '[a-z0-9]+\.your-objectstorage\.com',
        'spaces' => '[a-z0-9]+\.digitaloceanspaces\.com',
    ];

    /**
     * Reads the bucket address the provider shows, in either style:
     * https://s3.eu-central-003.backblazeb2.com/my-bucket/folder or https://my-bucket.fra1.digitaloceanspaces.com.
     * @return array{provider:string,endpoint:string,bucket:string,prefix:string}
     */
    public static function fromUrl(string $url): array
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('#^(?:https?|s3)://#', '', $url);
        [$host, $path] = array_pad(explode('/', $url, 2), 2, '');
        foreach (self::ENDPOINTS as $provider => $pattern) {
            if (preg_match('/^(?:([a-z0-9][a-z0-9.-]*[a-z0-9])\.)?(' . $pattern . ')$/D', $host, $m)) {
                $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
                $bucket = $m[1] !== '' ? $m[1] : (string) array_shift($segments);
                if ($bucket === '') {
                    throw new \InvalidArgumentException('Add the bucket name to the address, for example https://' . $m[2] . '/my-bucket.');
                }
                return ['provider' => $provider, 'endpoint' => $m[2], 'bucket' => $bucket, 'prefix' => implode('/', $segments)];
            }
        }
        throw new \InvalidArgumentException('Paste the bucket address from Backblaze, Cloudflare R2, Wasabi, Hetzner or DigitalOcean, for example https://s3.eu-central-003.backblazeb2.com/my-bucket.');
    }

    /**
     * Backblaze B2 from the key alone: the account authorization names the S3 endpoint, and the bucket
     * when the key is limited to one (as recommended); otherwise the account's only bucket.
     * @return array{endpoint:string,bucket:string}
     */
    public static function discoverB2(string $keyId, string $secret): array
    {
        if ($keyId === '' || $secret === '') {
            throw new \InvalidArgumentException('Enter the Backblaze keyID and applicationKey.');
        }
        [$status, $body] = ProviderHttp::request('GET', 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account', ['api.backblazeb2.com'],
            ['Authorization' => 'Basic ' . base64_encode($keyId . ':' . $secret)], null, 20);
        $auth = ProviderHttp::json($body);
        if ($status !== 200 || empty($auth['s3ApiUrl'])) {
            throw new \InvalidArgumentException('Backblaze did not accept this key (HTTP ' . $status . ($auth['message'] ?? '' ? ': ' . $auth['message'] : '') . '). Check the keyID and applicationKey.');
        }
        $endpoint = self::endpointHost((string) $auth['s3ApiUrl']);
        $bucket = (string) ($auth['allowed']['bucketName'] ?? '');
        if ($bucket === '') {
            $apiHost = (string) parse_url((string) ($auth['apiUrl'] ?? ''), PHP_URL_HOST);
            if (!str_ends_with($apiHost, '.backblazeb2.com')) {
                throw new \InvalidArgumentException('Backblaze returned an unexpected API address.');
            }
            [$status, $body] = ProviderHttp::request('POST', 'https://' . $apiHost . '/b2api/v2/b2_list_buckets', [$apiHost],
                ['Authorization' => (string) ($auth['authorizationToken'] ?? ''), 'Content-Type' => 'application/json'], json_encode(['accountId' => (string) ($auth['accountId'] ?? '')]), 20);
            $names = array_column((array) (ProviderHttp::json($body)['buckets'] ?? []), 'bucketName');
            if ($status !== 200 || count($names) !== 1) {
                throw new \InvalidArgumentException(count($names) > 1
                    ? 'This key can reach ' . count($names) . ' buckets (' . implode(', ', array_slice($names, 0, 5)) . '). Paste the bucket address, or create a key for that bucket only.'
                    : 'No bucket found for this key. Create a private bucket in Backblaze first.');
            }
            $bucket = (string) $names[0];
        }
        return ['endpoint' => $endpoint, 'bucket' => $bucket];
    }

    /** Accepts "https://host/" or "host"; returns the bare host name. */
    public static function endpointHost(string $endpoint): string
    {
        $host = strtolower(trim($endpoint));
        $host = preg_replace('#^https://#', '', $host) ?? '';
        return rtrim($host, '/');
    }

    /** The signing region: typed, or read from the endpoint (s3.<region>.backblazeb2.com, R2 uses "auto"). */
    public static function regionFor(string $host, string $typed): string
    {
        $typed = strtolower(trim($typed));
        if ($typed !== '') {
            return $typed;
        }
        return match (true) {
            str_ends_with($host, '.r2.cloudflarestorage.com') => 'auto',
            (bool) preg_match('/^s3\.([a-z0-9-]+)\.(backblazeb2\.com|wasabisys\.com)$/', $host, $m) => $m[1],
            (bool) preg_match('/^([a-z0-9-]+)\.(your-objectstorage\.com|digitaloceanspaces\.com)$/', $host, $m) => $m[1],
            default => 'us-east-1',
        };
    }

    public static function validate(array $c): void
    {
        $host = self::endpointHost((string) ($c['endpoint'] ?? ''));
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
            throw new \InvalidArgumentException('Enter the S3 endpoint host, for example s3.eu-central-003.backblazeb2.com or <account id>.r2.cloudflarestorage.com (without a path).');
        }
        $domain = self::DOMAINS[$c['provider'] ?? ''] ?? null;
        if ($domain === null) {
            throw new \InvalidArgumentException('Choose the storage provider under Type.');
        }
        if (!str_ends_with($host, $domain)) {
            throw new \InvalidArgumentException('A ' . self::PROVIDERS[$c['provider']] . ' endpoint ends in ' . $domain . '. Copy it from the provider (see Documentation → Backups).');
        }
        if (!preg_match('/^[a-z0-9-]{2,32}$/D', $c['region'] ?? '') || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $c['bucket'] ?? '')
            || !preg_match('/^[A-Za-z0-9]{12,128}$/D', $c['access_key'] ?? '') || ($c['secret_key'] ?? '') === '' || !preg_match('#^[A-Za-z0-9._/-]*$#D', $c['prefix'] ?? '')) {
            throw new \InvalidArgumentException('Enter the bucket, access key ID, secret access key and an optional folder (letters, digits, . _ - /). The region is filled from the endpoint when left empty.');
        }
    }

    public function put(string $file, string $name): void
    {
        $hash = hash_file('sha256', $file) ?: throw new RuntimeException('Cannot read ' . basename($file) . '.');
        [$status, $body] = $this->call('PUT', $this->key($name), [], ['file' => $file], $hash, 900);
        if ($status !== 200) {
            throw new RuntimeException('Upload failed (HTTP ' . $status . '): ' . self::error($body));
        }
    }

    public function list(): array
    {
        $files = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $this->key('frweb-')] + ($token !== null ? ['continuation-token' => $token] : []);
            [$status, $body] = $this->call('GET', '', $query);
            if ($status !== 200) {
                throw new RuntimeException('Listing failed (HTTP ' . $status . '): ' . self::error($body));
            }
            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new RuntimeException('The storage returned an unreadable listing.');
            }
            foreach ($xml->Contents as $item) {
                $key = (string) $item->Key;
                $files[basename($key)] = $key;
            }
            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null);
        return $files;
    }

    public function delete(string $id): void
    {
        [$status, $body] = $this->call('DELETE', $id);
        if ($status !== 204 && $status !== 200) {
            throw new RuntimeException('Delete failed (HTTP ' . $status . '): ' . self::error($body));
        }
    }

    private function key(string $name): string
    {
        $prefix = trim((string) ($this->c['prefix'] ?? ''), '/');
        return ($prefix !== '' ? $prefix . '/' : '') . $name;
    }

    /**
     * @param array{file: string}|null $body
     * @return array{0: int, 1: string}
     */
    private function call(string $method, string $key, array $query = [], ?array $body = null, string $payloadHash = '', int $timeout = 30): array
    {
        $region = $this->c['region'];
        $host = self::endpointHost((string) $this->c['endpoint']);
        $path = '/' . rawurlencode($this->c['bucket']) . ($key !== '' ? '/' . implode('/', array_map('rawurlencode', explode('/', $key))) : '');
        ksort($query);
        $canonicalQuery = implode('&', array_map(static fn ($k, $v) => rawurlencode((string) $k) . '=' . rawurlencode((string) $v), array_keys($query), $query));
        $payloadHash = $payloadHash !== '' ? $payloadHash : hash('sha256', '');
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $now];
        $signed = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, $canonicalQuery, implode('', array_map(static fn ($k, $v) => $k . ':' . $v . "\n", array_keys($headers), $headers)), $signed, $payloadHash]);
        $scope = $date . '/' . $region . '/s3/aws4_request';
        $toSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4' . $this->c['secret_key'], true), true), true), true);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->c['access_key'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . hash_hmac('sha256', $toSign, $signingKey);
        $url = 'https://' . $host . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        [$status, $response] = ProviderHttp::request($method, $url, [$host], [
            'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $now, 'Authorization' => $authorization,
        ], $body, $timeout, 4194304);
        return [$status, $response];
    }

    private static function error(string $body): string
    {
        $xml = @simplexml_load_string($body);
        return $xml !== false ? trim((string) $xml->Code . ' ' . (string) $xml->Message) : mb_substr(trim($body), 0, 200);
    }
}
