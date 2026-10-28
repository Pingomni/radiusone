<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RadiusOne Pro: a licence key under Settings → License turns on the Pro features. Without one RadiusOne runs
 * as RadiusOne Community: clients, services, NAS, RADIUS, the network queue, reports and local backups, for
 * up to COMMUNITY_CLIENTS clients. A Pro key also sets how many clients the server may hold, by the licence
 * bought: Licence 1 up to 999, Licence 2 up to 4,999, Licence 3 no limit.
 *
 * A key is RADIUSONE1.<base64url payload JSON>.<base64url Ed25519 signature of the payload>, signed by
 * PingOmni (the account portal, account.pingomni.com) with the same key as DNSOne's. The payload is
 * {"v":1,"id","customer","issued","expires","features","clients"}, features ["all"] or feature names
 * (FEATURES), clients the limit (0: none; missing: PRO_CLIENTS).
 *
 * An expired licence never stops RADIUS or a Pro feature already set up: its pages stay readable and its
 * jobs keep running, but nothing in it can be changed until the licence is renewed, and new clients are
 * held to the Community limit. Over a limit, every client keeps working; only adding new ones is refused.
 *
 * @phpstan-type Licence array{state: string, customer?: string, id?: string, expires?: string, features?: list<string>, clients?: int|null, error?: string}
 */
final class License
{
    public const PREFIX = 'RADIUSONE1';

    /** Clients (not archived) a server may hold without a licence, and with a Pro key that does not say. */
    public const COMMUNITY_CLIENTS = 150;

    public const PRO_CLIENTS = 999;

    /** The Pro features: licence feature name => what it is called in the portal. */
    public const FEATURES = [
        'billing' => 'Billing and invoices',
        'notifications' => 'Customer notifications and admin alerts',
        'monitoring' => 'NAS monitoring and traffic',
        'backups' => 'Remote backups',
        'branding' => 'Your logo',
    ];

    /** @var list<string> PingOmni's licence public keys (base64). Tests add their own. */
    public static array $publicKeys = ['rpqda1rxFGTFKNyIsZZZhtqQ3Yj2ftn2d/RADOhGeh8='];

    /** @var Licence|null */
    private static ?array $current = null;

    /**
     * This server's licence: state none | active | expired | invalid, with its details while it has a key.
     *
     * @return Licence
     */
    public static function current(): array
    {
        return self::$current ??= self::describe(Settings::value('license_key', ''));
    }

    /** @return Licence */
    public static function describe(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['state' => 'none'];
        }
        $payload = self::verify($key);
        if ($payload === null) {
            return ['state' => 'invalid', 'error' => 'This is not a RadiusOne licence key, or it was changed.'];
        }
        $features = array_values(array_filter((array) ($payload['features'] ?? []), 'is_string'));
        $clients = $payload['clients'] ?? self::PRO_CLIENTS;

        return [
            'state' => (string) $payload['expires'] < date('Y-m-d') ? 'expired' : 'active',
            'customer' => (string) ($payload['customer'] ?? ''),
            'id' => (string) ($payload['id'] ?? ''),
            'expires' => (string) $payload['expires'],
            'features' => in_array('all', $features, true) ? array_keys(self::FEATURES) : array_values(array_intersect($features, array_keys(self::FEATURES))),
            // Never fewer than Community allows.
            'clients' => is_int($clients) && $clients > 0 ? max($clients, self::COMMUNITY_CLIENTS) : null,
        ];
    }

    /** How many clients (not archived) this server may hold now; null: no limit. */
    public static function clientLimit(): ?int
    {
        $licence = self::current();

        return $licence['state'] === 'active' ? ($licence['clients'] ?? null) : self::COMMUNITY_CLIENTS;
    }

    /** Clients that count toward the limit: every one not archived (suspended and expired ones too). */
    public static function clientCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM customer_profiles WHERE account_status <> "archived"');
    }

    /** Refuses a new client once the server holds as many as its licence allows. Existing clients are never affected. */
    public static function assertRoomForClient(): void
    {
        $limit = self::clientLimit();
        if ($limit !== null && self::clientCount() >= $limit) {
            throw new \RuntimeException(match (self::current()['state']) {
                'active' => "This RadiusOne Pro licence covers {$limit} clients, and they are all in use. Archive clients you no longer serve, or move to a licence for more clients in your PingOmni account (account.pingomni.com): Licence 2 covers 4,999, Licence 3 has no limit.",
                'expired' => "The RadiusOne Pro licence has expired: new clients are limited to {$limit} until it is renewed. Existing clients keep working.",
                default => "RadiusOne Community covers up to {$limit} clients. Archive clients you no longer serve, or add a RadiusOne Pro licence under Settings → License.",
            });
        }
    }

    /** Whether a Pro feature may run (an active or expired licence for it), or, with $write, be changed (active only). */
    public static function allows(string $feature, bool $write = false): bool
    {
        $licence = self::current();
        if (!in_array($feature, $licence['features'] ?? [], true)) {
            return false;
        }

        return $licence['state'] === 'active' || (!$write && $licence['state'] === 'expired');
    }

    /** @return array<string, bool> every Pro feature and whether it may run, for the pages */
    public static function features(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $out[$feature] = self::allows($feature);
        }

        return $out;
    }

    /**
     * Saves a key (it must be valid) or, with '', removes it.
     *
     * @return Licence
     */
    public static function save(string $key): array
    {
        $key = preg_replace('/\s+/', '', $key) ?? '';
        $licence = self::describe($key);
        if ($licence['state'] === 'invalid') {
            return $licence;
        }
        Settings::save('license_key', $key);
        self::$current = null;

        return $licence;
    }

    /** Forgets the cached licence (after a change, and in tests). */
    public static function reset(): void
    {
        self::$current = null;
    }

    /** @return array<string, mixed>|null the payload of a correctly signed RadiusOne key */
    private static function verify(string $key): ?array
    {
        $parts = explode('.', $key);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        $payload = self::b64($parts[1]);
        $signature = self::b64($parts[2]);
        if ($payload === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        foreach (self::$publicKeys as $public) {
            $public = base64_decode($public, true);
            if ($public !== false && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $payload, $public)) {
                $data = json_decode($payload, true);

                return is_array($data) && ($data['v'] ?? null) === 1 && is_string($data['expires'] ?? null)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['expires']) && self::forRadiusOne($data) ? $data : null;
            }
        }

        return null;
    }

    /**
     * The key was issued for RadiusOne: PingOmni signs every product's keys with one key, and the prefix is not
     * signed. Keys name their product since 29 October 2026; older RadiusOne keys always carry a client limit
     * ("clients"), which DNSOne keys never have.
     *
     * @param array<string, mixed> $data
     */
    private static function forRadiusOne(array $data): bool
    {
        return array_key_exists('product', $data) ? $data['product'] === 'radiusone' : array_key_exists('clients', $data);
    }

    private static function b64(string $text): ?string
    {
        $out = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $out === false ? null : $out;
    }
}
