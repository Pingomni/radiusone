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
    public const TYPES = ['gdrive' => 'Google Drive', 's3' => 'Amazon S3', 'sftp' => 'SFTP server'];
    public const KEEP = [7 => '7 copies', 14 => '14 copies', 30 => '30 copies', 60 => '60 copies', 90 => '90 copies'];
    public const LOCAL_DAYS = [7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days'];

    private const FIELDS = [
        'gdrive' => ['client_id', 'folder'],
        's3' => ['region', 'bucket', 'prefix', 'access_key'],
        'sftp' => ['host', 'port', 'user', 'path'],
    ];
    private const SECRETS = ['gdrive' => ['client_secret', 'refresh_token'], 's3' => ['secret_key'], 'sftp' => ['private_key']];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $saved = json_decode(Settings::value('backup_destinations', '[]'), true);
        return is_array($saved) ? array_values($saved) : [];
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
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Give the destination a name of up to 60 characters.');
        }
        $destination = $current ?? ['id' => bin2hex(random_bytes(6)), 'type' => $type];
        $destination['name'] = $name;
        $destination['enabled'] = !empty($input['enabled']);
        $destination['keep'] = isset(self::KEEP[(int) ($input['keep'] ?? 0)]) ? (int) $input['keep'] : 14;
        foreach (self::FIELDS[$type] as $field) {
            $destination[$field] = trim((string) ($input[$field] ?? ''));
        }
        foreach (self::SECRETS[$type] as $field) {
            $typed = (string) ($input[$field] ?? '');
            if ($field !== 'refresh_token' && $field !== 'private_key' && $typed !== '') {
                $destination[$field] = $typed;
            }
            $destination[$field] ??= '';
        }

        if ($type === 's3') {
            $destination['region'] = strtolower($destination['region']);
            S3Target::validate($destination);
        } elseif ($type === 'gdrive') {
            GoogleDriveTarget::validate($destination);
            // A different OAuth client or folder needs a new sign-in.
            if ($current !== null && ($current['client_id'] !== $destination['client_id'] || $current['folder'] !== $destination['folder'])) {
                $destination['refresh_token'] = '';
                $destination['folder_id'] = '';
            }
        } else {
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
            'gdrive' => new GoogleDriveTarget($destination),
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
            $d['connected'] = $d['type'] !== 'gdrive' || $d['refresh_token_saved'];
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
