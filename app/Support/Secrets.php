<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;

/**
 * Encryption at rest for credentials only the portal uses: RouterOS API passwords, SNMP communities
 * and v3 passwords, notification gateway secrets. The key is derived from APP_KEY (in the protected
 * .env), so a database dump alone no longer reveals them.
 *
 * Not for nas.secret: FreeRADIUS reads the RADIUS shared secret from the database directly.
 * Stored form: "enc:v1:" + base64(nonce || secretbox). Values without the prefix are legacy
 * plaintext and are returned unchanged until bin/encrypt-secrets.php converts them.
 */
final class Secrets
{
    private const PREFIX = 'enc:v1:';
    /** Plaintext limit so the stored form fits a VARCHAR(255) column. */
    public const MAX_LENGTH = 140;

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || self::isEncrypted($plain)) {
            return $plain;
        }
        return self::seal($plain);
    }

    private static function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    private static function open(string $stored, string $key): ?string
    {
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $plain === false ? null : $plain;
    }

    /**
     * Plaintext for a stored value; '' when it cannot be decrypted (APP_KEY changed or data corrupted).
     * During a key change, values sealed with APP_KEY_PREVIOUS still open until encryptStored() re-seals them.
     */
    public static function reveal(?string $stored): string
    {
        $stored = (string) $stored;
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        $plain = self::open($stored, self::key());
        if ($plain === null && ($previous = self::previousKey()) !== null) {
            $plain = self::open($stored, $previous);
        }
        return $plain ?? '';
    }

    public static function isEncrypted(?string $stored): bool
    {
        return str_starts_with((string) $stored, self::PREFIX);
    }

    /** True when a stored value is encrypted but does not open with the current key. */
    public static function unreadable(?string $stored): bool
    {
        return self::isEncrypted($stored) && self::reveal($stored) === '';
    }

    /** True when a stored value is encrypted but opens only with APP_KEY_PREVIOUS. */
    public static function needsRekey(?string $stored): bool
    {
        return self::isEncrypted($stored) && self::open((string) $stored, self::key()) === null && self::reveal($stored) !== '';
    }

    /**
     * Encrypts stored portal-only credentials that are still plaintext, and re-seals values that open
     * only with APP_KEY_PREVIOUS under the current APP_KEY, in one transaction.
     * Rerunning skips values already sealed with the current key. Returns counts per column, never values.
     * @return array<string, array{encrypted:int,rekeyed:int,already:int,unreadable:int}>
     */
    public static function encryptStored(): array
    {
        $targets = [
            ['nas_metadata', 'nas_id', ['api_password'], '1=1'],
            ['nas_snmp', 'nas_id', ['community', 'v3_auth_password', 'v3_priv_password'], '1=1'],
            ['settings', 'setting_key', ['setting_value'], 'is_secret = 1'],
        ];
        $summary = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($targets as [$table, $key, $columns, $where]) {
                foreach ($columns as $column) {
                    if (!Db::value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column])) { continue; }
                    $counts = ['encrypted' => 0, 'rekeyed' => 0, 'already' => 0, 'unreadable' => 0];
                    foreach (Db::select("SELECT `$key` AS k, `$column` AS v FROM `$table` WHERE $where AND `$column` IS NOT NULL AND `$column` <> '' FOR UPDATE") as $row) {
                        $outcome = 'encrypted';
                        $plain = (string) $row['v'];
                        if (self::isEncrypted($row['v'])) {
                            if (self::unreadable($row['v'])) { $counts['unreadable']++; continue; }
                            if (!self::needsRekey($row['v'])) { $counts['already']++; continue; }
                            $outcome = 'rekeyed';
                            $plain = self::reveal($row['v']);
                        }
                        $sealed = self::seal($plain);
                        if (self::open($sealed, self::key()) !== $plain) { throw new RuntimeException("Round-trip check failed for $table.$column."); }
                        Db::exec("UPDATE `$table` SET `$column`=? WHERE `$key`=? AND `$column`=?", [$sealed, $row['k'], $row['v']]);
                        $counts[$outcome]++;
                    }
                    $summary["$table.$column"] = $counts;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return $summary;
    }

    private static function key(): string
    {
        $appKey = (string) App::env('APP_KEY', '');
        if (strlen($appKey) < 32 || str_contains($appKey, 'change-me')) {
            if (App::env('APP_ENV', '') !== 'test') {
                throw new RuntimeException('Set a random APP_KEY (32+ characters) in .env before saving device or gateway credentials.');
            }
            $appKey = 'frweb-test-only-key-not-for-production-use';
        }
        return self::derive($appKey);
    }

    /** Key from APP_KEY_PREVIOUS, set only while rotating APP_KEY; null when unset or same as APP_KEY. */
    private static function previousKey(): ?string
    {
        $previous = (string) App::env('APP_KEY_PREVIOUS', '');
        if (strlen($previous) < 32 || $previous === (string) App::env('APP_KEY', '')) {
            return null;
        }
        return self::derive($previous);
    }

    private static function derive(string $appKey): string
    {
        // Separate from other APP_KEY uses (login throttle HMAC) by a fixed label.
        return sodium_crypto_generichash('frweb:secrets:v1:' . $appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
