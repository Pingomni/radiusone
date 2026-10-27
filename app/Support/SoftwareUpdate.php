<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Settings → Overview → Software update, as DNSOne does it. The portal reads the latest release from GitHub
 * (each release carries radiusone-X.Y.Z.tar.gz, built by .github/workflows/release.yml) and, to install it,
 * only leaves a request in /var/lib/radiusone/update: radiusone-update.path starts radiusone-update.service,
 * which runs ops/radiusone-update --console as root and writes its progress to /var/lib/radiusone/update-status
 * (status.json, update.log), which this page shows. The web server runs no update command itself.
 *
 * A server still laid out as before 2.3 (the freeradius-web names) also gets "Finish moving to RadiusOne":
 * the same request with action "move", which runs ops/radiusone-upgrade-2.3.
 */
final class SoftwareUpdate
{
    /** Where the releases are; RADIUSONE_UPDATE_REPO overrides it (a fork). */
    public const REPO = 'n-geraldo/radiusone';

    /** Where the app lives once moved (ops/radiusone-upgrade-2.3). */
    public const HOME = '/opt/radiusone';

    private const CHECK = 'software_update_check';

    /** Tests point these at a temporary directory. */
    public static ?string $requestDir = null;

    public static ?string $statusDir = null;

    /** The updater before 2.3 wrote its result and log here. */
    public static ?string $legacyStatusFile = null;

    public static ?string $legacyLogFile = null;

    public static string $home = self::HOME;

    /** The configuration folder before 2.3. */
    public static string $legacyEtc = '/etc/freeradius-web';

    public static function repo(): string
    {
        $repo = (string) config('app.update_repo', self::REPO);

        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo) ? $repo : self::REPO;
    }

    private static function requestDir(): string
    {
        return self::$requestDir ?? Paths::data('update');
    }

    private static function statusDir(): string
    {
        return self::$statusDir ?? Paths::data('update-status');
    }

    /** Installing from the portal is set up: the request folder exists and the web server may write in it. */
    public static function ready(): bool
    {
        return is_dir(self::requestDir()) && is_writable(self::requestDir());
    }

    /** An install was asked for and has not finished yet. */
    public static function running(): bool
    {
        if (is_file(self::requestDir() . '/request')) {
            return true;
        }
        $status = self::readStatus();

        return ($status['state'] ?? '') === 'running' && self::fresh($status);
    }

    /** This server still uses the layout from before 2.3 (the app outside /opt/radiusone, or the old folders). */
    public static function needsMove(): bool
    {
        $here = realpath(base_path()) ?: base_path();

        return $here !== self::$home || is_dir(self::$legacyEtc) && !is_link(self::$legacyEtc);
    }

    /**
     * Reads the latest release from GitHub and remembers the answer for the page.
     *
     * @return array<string, mixed>
     */
    public static function check(): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)->withUserAgent('RadiusOne/' . self::current())
                ->get('https://api.github.com/repos/' . self::repo() . '/releases/latest');
        } catch (Throwable $e) {
            throw new RuntimeException('GitHub could not be reached: ' . mb_substr($e->getMessage(), 0, 200));
        }
        if (!$response->successful()) {
            throw new RuntimeException('GitHub answered HTTP ' . $response->status() . ' for the latest release of ' . self::repo() . '.');
        }
        $tag = (string) $response->json('tag_name', '');
        if (!preg_match('/^v(\d+\.\d+\.\d+)$/D', $tag, $m)) {
            throw new RuntimeException('The latest release is not a vX.Y.Z version: ' . mb_substr($tag, 0, 40));
        }
        $assets = array_column((array) $response->json('assets', []), 'name');
        $check = [
            'at' => date('Y-m-d H:i'),
            'current' => self::current(),
            'latest' => $m[1],
            'newer' => version_compare($m[1], self::current(), '>'),
            'tag' => $tag,
            'notes' => mb_substr((string) $response->json('body', ''), 0, 6000),
            'url' => (string) $response->json('html_url', 'https://github.com/' . self::repo() . '/releases/tag/' . $tag),
            'published' => substr((string) $response->json('published_at', ''), 0, 10),
            'problem' => in_array('radiusone-' . $m[1] . '.tar.gz', $assets, true) ? '' : 'Release ' . $m[1] . ' has no download yet; try again in a few minutes.',
        ];
        Settings::save(self::CHECK, json_encode($check, JSON_THROW_ON_ERROR));

        return $check;
    }

    /**
     * The last check, or null; "newer" turns false once the installed version has caught up with it.
     *
     * @return array{at:string,current:string,latest:string,newer:bool,tag:string,notes:string,url:string,published:string,problem:string}|null
     */
    public static function lastCheck(): ?array
    {
        $saved = json_decode(Settings::value(self::CHECK, ''), true);
        if (!is_array($saved)) {
            return null;
        }
        $check = ['newer' => !empty($saved['newer'])];
        foreach (['at', 'current', 'latest', 'tag', 'notes', 'url', 'published', 'problem'] as $key) {
            $check[$key] = is_scalar($saved[$key] ?? null) ? (string) $saved[$key] : '';
        }
        if ($check['newer'] && !version_compare($check['latest'], self::current(), '>')) {
            $check['newer'] = false;
        }

        return $check;
    }

    /** Asks radiusone-update to install the release found by the last check; returns its version. */
    public static function start(): string
    {
        $check = self::lastCheck();
        if (!$check || !$check['newer'] || !preg_match('/^v\d+\.\d+\.\d+$/D', $check['tag'])) {
            throw new InvalidArgumentException('Check for updates first: there is no newer release to install.');
        }
        if ($check['problem'] !== '') {
            throw new InvalidArgumentException($check['problem']);
        }
        self::request(['action' => 'install', 'tag' => $check['tag']]);

        return $check['latest'];
    }

    /** Asks radiusone-update to move this server to the RadiusOne layout (ops/radiusone-upgrade-2.3). */
    public static function move(): void
    {
        if (!self::needsMove()) {
            throw new InvalidArgumentException('This server already uses the RadiusOne layout.');
        }
        self::request(['action' => 'move']);
    }

    /** @param array<string, string> $request */
    private static function request(array $request): void
    {
        if (!self::ready()) {
            throw new RuntimeException('Installing from the portal is not set up on this server. Run sudo radiusone-update once on it.');
        }
        if (self::running()) {
            throw new InvalidArgumentException('An update is already running.');
        }
        $user = Auth::user();
        $file = self::requestDir() . '/request';
        $body = json_encode($request + ['by' => (string) ($user['username'] ?? ''), 'at' => date('c')], JSON_THROW_ON_ERROR);
        if (@file_put_contents($file . '.new', $body) === false || !@rename($file . '.new', $file)) {
            throw new RuntimeException('The update request could not be written in ' . self::requestDir() . '.');
        }
    }

    public static function current(): string
    {
        return (string) config('app.version');
    }

    /**
     * The last update's result: state running, ok or failed, with a message. radiusone-update's status.json, or
     * the result of the updater before 2.3 (update-status.json) on a server that has not run the new one yet.
     *
     * @return array{state:string,step:string,message:string,from:string,to:string,at:string}|null
     */
    public static function status(): ?array
    {
        $raw = self::readStatus();
        if ($raw === null) {
            $legacy = self::$legacyStatusFile ?? Paths::data('update-status.json');
            $old = is_readable($legacy) ? json_decode((string) @file_get_contents($legacy), true) : null;
            if (!is_array($old)) {
                return null;
            }
            $keys = ['state', 'step', 'message', 'from', 'to', 'at'];

            return array_map('strval', array_intersect_key($old + array_fill_keys($keys, ''), array_flip($keys)));
        }
        $state = match ($raw['state'] ?? '') {
            'running' => self::fresh($raw) ? 'running' : 'failed',
            'done' => 'ok',
            default => 'failed',
        };
        $from = (string) ($raw['from'] ?? '');
        $to = (string) ($raw['to'] ?? '');
        $move = ($raw['action'] ?? '') === 'move';
        $message = match ($state) {
            'running' => $move ? 'Moving this server to the RadiusOne layout.' : 'Installing RadiusOne ' . $to . '.',
            'ok' => $move ? 'Moved to the RadiusOne layout.' : ($from !== '' && $from !== $to ? "Updated from {$from} to {$to}." : "RadiusOne {$to} is installed."),
            default => (($raw['state'] ?? '') === 'running' ? 'The update stopped without finishing.' : ($move ? 'The move did not finish.' : 'The update to ' . $to . ' failed.'))
                . ' RadiusOne ' . self::current() . ' runs. See the log.',
        };

        return ['state' => $state, 'step' => (string) ($raw['step'] ?? ''), 'message' => $message, 'from' => $from, 'to' => $to,
            'at' => substr(str_replace('T', ' ', (string) (($raw['finished'] ?? '') ?: ($raw['started'] ?? ''))), 0, 16)];
    }

    /** @return list<string> the last lines the updater logged (newest last) */
    public static function log(int $lines = 40): array
    {
        $file = self::readStatus() !== null ? self::statusDir() . '/update.log' : self::$legacyLogFile ?? Paths::logs('update.log');
        $size = is_readable($file) ? (int) @filesize($file) : 0;
        $text = $size ? (string) @file_get_contents($file, false, null, max(0, $size - 16384)) : '';
        $rows = array_values(array_filter(array_map('rtrim', explode("\n", $text)), static fn ($l) => trim($l) !== ''));

        return array_slice($rows, -$lines);
    }

    /** @return array<string, mixed>|null radiusone-update's status.json */
    private static function readStatus(): ?array
    {
        $file = self::statusDir() . '/status.json';
        $status = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($status) ? $status : null;
    }

    /**
     * A "running" status is believed for an hour: the updater's own limit (TimeoutStartSec).
     *
     * @param  array<string, mixed>  $status
     */
    private static function fresh(array $status): bool
    {
        $started = strtotime((string) ($status['started'] ?? ''));

        return $started !== false && time() - $started < 3600;
    }
}
