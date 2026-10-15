<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\ProviderHttp;
use RuntimeException;

/**
 * Amazon S3 with AWS Signature V4 (path-style URLs, so bucket names with dots keep a valid
 * certificate). The access key needs s3:PutObject, s3:ListBucket and s3:DeleteObject on the prefix.
 */
final class S3Target implements BackupTarget
{
    public function __construct(private readonly array $c)
    {
    }

    public static function validate(array $c): void
    {
        if (!preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d$/D', $c['region'] ?? '') || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $c['bucket'] ?? '')
            || !preg_match('/^[A-Z0-9]{16,128}$/D', $c['access_key'] ?? '') || ($c['secret_key'] ?? '') === '' || !preg_match('#^[A-Za-z0-9._/-]*$#D', $c['prefix'] ?? '')) {
            throw new \InvalidArgumentException('Enter the S3 region (for example eu-central-1), bucket, access key ID, secret access key and an optional folder (letters, digits, . _ - /).');
        }
    }

    public function put(string $file, string $name): void
    {
        $hash = hash_file('sha256', $file) ?: throw new RuntimeException('Cannot read ' . basename($file) . '.');
        [$status, $body] = $this->call('PUT', $this->key($name), [], ['file' => $file], $hash, 900);
        if ($status !== 200) {
            throw new RuntimeException('S3 upload failed (HTTP ' . $status . '): ' . self::error($body));
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
                throw new RuntimeException('S3 listing failed (HTTP ' . $status . '): ' . self::error($body));
            }
            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new RuntimeException('S3 returned an unreadable listing.');
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
            throw new RuntimeException('S3 delete failed (HTTP ' . $status . '): ' . self::error($body));
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
        $host = 's3.' . $region . '.amazonaws.com';
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
