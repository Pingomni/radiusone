<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\Settings;
use InvalidArgumentException;

/**
 * Remote backup destinations and the backup passphrase, kept encrypted in settings
 * (Settings → Backups). Credentials are merged on save (a blank field keeps the saved one) and are
 * never sent to the browser.
 */
final class BackupDestinations
{
    public const TYPES = ['s3' => 'Backblaze B2 or Cloudflare R2', 'sftp' => 'SFTP server'];
    public const KEEP = [1 => '1 copy', 3 => '3 copies', 5 => '5 copies', 7 => '7 copies', 14 => '14 copies', 30 => '30 copies', 60 => '60 copies', 90 => '90 copies'];
    public const LOCAL_DAYS = [7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days'];

    private const FIELDS = [
        's3' => ['provider', 'endpoint', 'region', 'bucket', 'prefix', 'access_key'],
        'sftp' => ['host', 'port', 'user', 'path'],
    ];
    private const DEFAULTS = ['provider' => 'b2', 'prefix' => 'freeradius-web', 'port' => '22', 'path' => 'backups/freeradius-web'];
    private const SECRETS = ['s3' => ['secret_key'], 'sftp' => ['private_key']];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $saved = json_decode(Settings::value('backup_destinations', '[]'), true);
        // Retired types and providers (Google Drive, Amazon-only S3, Wasabi, Hetzner, DigitalOcean) are dropped.
        return is_array($saved) ? array_values(array_filter($saved, static fn ($d) => isset(self::TYPES[$d['type'] ?? ''])
            && ($d['type'] !== 's3' || (($d['endpoint'] ?? '') !== '' && isset(S3Target::PROVIDERS[$d['provider'] ?? '']))))) : [];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $destination) {
            if ($destination['id'] === $id) {
                return $destination;
            }
        }
        return null;
    }

    /** Creates or updates a destination; returns its id. */
    public static function save(array $input): string
    {
        $id = (string) ($input['id'] ?? '');
        $current = $id !== '' ? self::find($id) : null;
        if ($id !== '' && $current === null) {
            throw new InvalidArgumentException('That destination no longer exists.');
        }
        $type = $current['type'] ?? (string) ($input['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Choose a destination type.');
        }
        $destination = $current ?? ['id' => bin2hex(random_bytes(6)), 'type' => $type];
        $destination['enabled'] = !empty($input['enabled']);   // an unchecked box is not sent
        $destination['keep'] = isset(self::KEEP[(int) ($input['keep'] ?? 0)]) ? (int) $input['keep'] : ($current['keep'] ?? 7);
        // A field that is not sent keeps its saved value (or the default for a new destination).
        foreach (self::FIELDS[$type] as $field) {
            $destination[$field] = trim((string) ($input[$field] ?? ($current[$field] ?? self::DEFAULTS[$field] ?? '')));
        }
        // A blank secret keeps the saved one, which only belongs to the saved key.
        if ($current !== null && $type === 's3' && $destination['access_key'] !== $current['access_key'] && (string) ($input['secret_key'] ?? '') === '') {
            throw new InvalidArgumentException('You changed the key ID: paste the secret that belongs to it as well (for Backblaze, the applicationKey shown with the new keyID).');
        }
        foreach (self::SECRETS[$type] as $field) {
            $typed = (string) ($input[$field] ?? '');
            if ($field !== 'private_key' && $typed !== '') {
                $destination[$field] = $typed;
            }
            $destination[$field] ??= '';
        }

        if ($type === 's3') {
            // The bucket address the provider shows names the provider, endpoint, bucket and optional folder.
            $url = trim((string) ($input['url'] ?? ''));
            if ($url !== '') {
                $parsed = S3Target::fromUrl($url);
                $destination = array_replace($destination, ['provider' => $parsed['provider'], 'endpoint' => $parsed['endpoint'], 'bucket' => $parsed['bucket'], 'region' => ''],
                    $parsed['prefix'] !== '' ? ['prefix' => $parsed['prefix']] : []);
            } elseif ($destination['provider'] === 'b2' && $destination['endpoint'] === '') {
                // Backblaze: the key alone is enough.
                $destination = array_replace($destination, S3Target::discoverB2($destination['access_key'], $destination['secret_key']), ['region' => '']);
            }
            $destination['provider'] = isset(S3Target::PROVIDERS[$destination['provider']]) ? $destination['provider'] : '';
            $destination['endpoint'] = S3Target::endpointHost($destination['endpoint']);
            $destination['region'] = S3Target::regionFor($destination['endpoint'], $destination['region']);
            S3Target::validate($destination);
        } else {
            // "user@host" or "user@host:port" in one field.
            $server = trim((string) ($input['server'] ?? ''));
            if ($server !== '') {
                if (!preg_match('/^(?:([A-Za-z0-9._-]{1,64})@)?([A-Za-z0-9.-]{1,253})(?::(\d{1,5}))?$/D', $server, $m)) {
                    throw new InvalidArgumentException('Enter the server as user@host, or user@host:port when SSH does not listen on port 22.');
                }
                $destination['user'] = $m[1] !== '' ? $m[1] : $destination['user'];
                $destination['host'] = $m[2];
                $destination['port'] = ($m[3] ?? '') !== '' ? $m[3] : '22';
            }
            $destination['port'] = (string) ((int) $destination['port'] ?: 22);
            SftpTarget::validate($destination);
            if ($current === null) {
                $destination = array_replace($destination, SftpTarget::generateKey());
            }
            // The server's host key is pinned when the server changes, or when the operator asks to re-check it.
            if ($current === null || $current['host'] !== $destination['host'] || $current['port'] !== $destination['port'] || !empty($input['rescan_host_key'])) {
                $destination = array_replace($destination, SftpTarget::scanHostKey($destination['host'], (int) $destination['port']));
            }
        }

        // The name is optional: "Backblaze B2 · isp-backups" or "backup@nas.example.net".
        $name = trim((string) ($input['name'] ?? ''));
        $name = $name !== '' ? $name : ($current['name'] ?? ($type === 's3'
            ? S3Target::PROVIDERS[$destination['provider']] . ' · ' . $destination['bucket']
            : $destination['user'] . '@' . $destination['host']));
        if (mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Give the destination a name of up to 60 characters.');
        }
        $destination['name'] = $name;

        $all = array_values(array_filter(self::all(), static fn ($d) => $d['id'] !== $destination['id']));
        $all[] = $destination;
        self::store($all);
        return $destination['id'];
    }

    public static function update(string $id, array $values): void
    {
        self::store(array_map(static fn ($d) => $d['id'] === $id ? array_replace($d, $values) : $d, self::all()));
    }

    public static function remove(string $id): void
    {
        self::store(array_values(array_filter(self::all(), static fn ($d) => $d['id'] !== $id)));
    }

    public static function target(array $destination): BackupTarget
    {
        return match ($destination['type']) {
            's3' => new S3Target($destination),
            'sftp' => new SftpTarget($destination),
            default => throw new InvalidArgumentException('Unknown destination type.'),
        };
    }

    /** Uploads, finds and deletes a small file, to prove the destination works end to end. */
    public static function test(array $destination): void
    {
        $target = self::target($destination);
        $name = 'frweb-test-' . bin2hex(random_bytes(4)) . '.txt';
        $file = tempnam(sys_get_temp_dir(), 'frweb-test-');
        file_put_contents($file, "freeradius-web backup destination test\n");
        try {
            $target->put($file, $name);
            $listed = $target->list();
            if (!isset($listed[$name])) {
                throw new \RuntimeException('The test file was uploaded but does not appear in the listing.');
            }
            $target->delete($listed[$name]);
        } finally {
            @unlink($file);
        }
    }

    /** Destinations for the browser: credentials become "saved" flags; public key and host fingerprint stay. */
    public static function forBrowser(): array
    {
        return array_map(static function (array $d): array {
            foreach (self::SECRETS[$d['type']] as $field) {
                $d[$field . '_saved'] = ($d[$field] ?? '') !== '';
                unset($d[$field]);
            }
            unset($d['known_hosts']);
            $d['connected'] = true;
            return $d;
        }, self::all());
    }

    public static function passphrase(): string
    {
        return Settings::value('backup_passphrase', '');
    }

    public static function setPassphrase(string $passphrase, string $confirm): void
    {
        BackupCrypto::assertPassphrase($passphrase);
        if (!hash_equals($passphrase, $confirm)) {
            throw new InvalidArgumentException('The two passphrases do not match.');
        }
        Settings::save('backup_passphrase', $passphrase, true);
    }

    public static function localDays(): int
    {
        $days = (int) Settings::value('backup_local_days', '30');
        return isset(self::LOCAL_DAYS[$days]) ? $days : 30;
    }

    public static function setLocalDays(int $days): void
    {
        if (!isset(self::LOCAL_DAYS[$days])) {
            throw new InvalidArgumentException('Choose one of the listed periods.');
        }
        Settings::save('backup_local_days', (string) $days);
    }

    private static function store(array $destinations): void
    {
        Settings::save('backup_destinations', json_encode(array_values($destinations), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }
}
