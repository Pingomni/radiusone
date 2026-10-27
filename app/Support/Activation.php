<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registering this server's licence with the PingOmni account portal (report only), as DNSOne does.
 *
 * Once a Pro key is saved, RadiusOne tells the portal which server it runs on: it activates
 * (POST /api/v1/activate with the key, a fingerprint and the host name), then checks in every CHECK_IN_HOURS
 * (POST /api/v1/check-in). The customer then sees the server, and its last check-in, on the licence's page in
 * their PingOmni account. `license:check-in` runs every five minutes from the scheduler; it only talks to the
 * portal when something is due, and never throws.
 *
 * Report only: nothing here turns a Pro feature on or off. A portal that cannot be reached, or refuses (the
 * licence already runs on another server), is shown under Settings → License and retried later; License alone
 * decides what the key allows. One thing the portal sends back is used: the licence's current key (a renewal
 * or a different client limit gets a new one), which replaces the saved key once License verifies it.
 *
 * The fingerprint is the sha256 of /etc/machine-id: stable across reboots and upgrades, and only its hash leaves
 * the server. RADIUSONE_ACCOUNT_URL="" turns registration off.
 */
final class Activation
{
    public const KEY = 'license_activation';

    public const CHECK_IN_HOURS = 12;

    /** After a failure: unreachable, retry soon; refused, retry twice a day. */
    private const RETRY_UNREACHABLE = 3600;

    private const RETRY_REFUSED = self::CHECK_IN_HOURS * 3600;

    public static function url(): string
    {
        return rtrim((string) config('services.pingomni_account.url'), '/');
    }

    /** This server's id for the portal: sha256 of its machine id (the host name when there is none). */
    public static function fingerprint(): string
    {
        $machine = @file_get_contents((string) config('services.pingomni_account.machine_id_file')) ?: '';

        return hash('sha256', 'radiusone:' . (trim($machine) ?: (string) gethostname()));
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $state = json_decode(Settings::value(self::KEY, ''), true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private static function store(array $state): void
    {
        Settings::save(self::KEY, $state === [] ? '' : (string) json_encode($state, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $state */
    private static function due(array $state, string $licenceId): bool
    {
        if (($state['license_id'] ?? null) !== $licenceId || empty($state['last_attempt'])) {
            return true; // a new key: register it now
        }
        $wait = match ($state['status'] ?? '') {
            'registered' => self::CHECK_IN_HOURS * 3600,
            'unreachable' => self::RETRY_UNREACHABLE,
            default => self::RETRY_REFUSED,
        };

        return time() - (int) strtotime((string) $state['last_attempt']) >= $wait;
    }

    private static function post(string $path, array $body): Response
    {
        return Http::baseUrl(self::url())->acceptJson()->timeout(8)->withUserAgent('RadiusOne')->post($path, $body);
    }

    /** @return array{0: string, 1: string} the portal's refusal code and message */
    private static function refusal(Response $response): array
    {
        $body = $response->json();

        return [(string) ($body['error'] ?? 'http_' . $response->status()),
            (string) ($body['message'] ?? 'The account portal answered HTTP ' . $response->status() . '.')];
    }

    /**
     * Activates or checks in when due (always, with $force); returns the stored state.
     *
     * @return array<string, mixed>
     */
    public static function run(bool $force = false): array
    {
        if (self::url() === '') {
            return self::state();
        }
        $state = self::state();
        $key = preg_replace('/\s+/', '', Settings::value('license_key', '')) ?? '';

        if ($key === '') {
            // The licence was removed: give the server up in the portal, then forget it.
            if (!empty($state['token'])) {
                try {
                    self::post('/api/v1/deactivate', ['token' => $state['token'], 'fingerprint' => $state['fingerprint'] ?? self::fingerprint()]);
                } catch (Throwable) {
                    // The portal frees it when its lease runs out.
                }
            }
            if ($state !== []) {
                self::store([]);
            }

            return [];
        }
        $licence = License::describe($key);
        if (!in_array($licence['state'], ['active', 'expired'], true)) {
            return $state;
        }
        $id = (string) ($licence['id'] ?? '');
        if (!$force && !self::due($state, $id)) {
            return $state;
        }

        $fingerprint = self::fingerprint();
        $hostname = (string) gethostname();
        $now = date('c');
        $base = ['license_id' => $id, 'fingerprint' => $fingerprint, 'hostname' => $hostname, 'last_attempt' => $now];
        try {
            $response = null;
            if (!empty($state['token']) && ($state['license_id'] ?? null) === $id && ($state['fingerprint'] ?? null) === $fingerprint) {
                $response = self::post('/api/v1/check-in', ['token' => $state['token'], 'fingerprint' => $fingerprint]);
                if (in_array($response->status(), [404, 409, 410], true)) {
                    $response = null; // this registration is gone (released, or another server): register again
                }
            }
            $response ??= self::post('/api/v1/activate', ['key' => $key, 'fingerprint' => $fingerprint, 'hostname' => $hostname]);
        } catch (ConnectionException $e) {
            $state = [...$state, ...$base, 'status' => 'unreachable', 'message' => self::url() . ' could not be reached.'];
            self::store($state);

            return $state;
        }

        if ($response->status() === 200) {
            $data = (array) $response->json();
            $state = [...$base, 'status' => 'registered', 'message' => '', 'token' => $data['token'] ?? ($state['token'] ?? null),
                'last_ok' => $now, 'lease_until' => $data['lease_until'] ?? null];
            // The licence's current key (renewed, or a different client limit): saved once License verifies it.
            $current = preg_replace('/\s+/', '', (string) ($data['key'] ?? '')) ?? '';
            if ($current !== '' && $current !== $key) {
                $saved = License::save($current);
                if ($saved['state'] !== 'invalid') {
                    $state['license_id'] = (string) ($saved['id'] ?? $id);
                    $state['message'] = 'The updated licence key from your PingOmni account was installed.';
                    Audit::log('update', 'setting', 'license_key', ['license' => $id], ['license' => $saved['id'] ?? null, 'expires' => $saved['expires'] ?? null, 'source' => 'account']);
                }
            }
        } elseif ($response->status() >= 500) {
            $state = [...$state, ...$base, 'status' => 'unreachable', 'message' => 'The account portal answered HTTP ' . $response->status() . '.'];
        } else {
            [$code, $message] = self::refusal($response);
            $state = [...$base, 'status' => $code === 'in_use' ? 'in_use' : 'refused', 'code' => $code, 'message' => $message];
        }
        self::store($state);

        return $state;
    }

    /** What Settings → License shows. @return array<string, mixed> */
    public static function public(): array
    {
        $state = self::state();

        return [
            'status' => self::url() === '' ? 'off' : ($state['status'] ?? 'pending'),
            'message' => $state['message'] ?? '',
            'hostname' => $state['hostname'] ?? null,
            'last_ok' => $state['last_ok'] ?? null,
            'account_url' => self::url(),
        ];
    }
}
