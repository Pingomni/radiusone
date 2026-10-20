<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Settings → Overview → Software update. The root helper ops/frweb-update checks the git remote for a
 * newer release tag and installs it in the background (frweb-update.service); the portal only asks it
 * to, and shows what it reports.
 */
final class SoftwareUpdate
{
    public const HELPER = '/usr/local/sbin/frweb-update';
    public const UNIT = 'frweb-update.service';
    public const LOG = '/var/log/freeradius-web/update.log';
    public const STATUS = '/var/lib/freeradius-web/update-status.json';
    /**
     * How the helper is run, and how the running state is read; tests point them at stand-ins.
     *
     * @var list<string>
     */
    public static array $command = ['sudo', '-n', self::HELPER];
    /** @var list<string> */
    public static array $isActive = ['systemctl', 'is-active', '--quiet', self::UNIT];
    public static string $statusFile = self::STATUS;
    public static string $logFile = self::LOG;

    private const CHECK = 'software_update_check';

    /** Whether the root helper is installed (ops/apply-service-control.sh). */
    public static function ready(): bool
    {
        return is_executable((string) end(self::$command));
    }

    public static function running(): bool
    {
        try {
            return BoundedProcess::run(self::$isActive, '', 3, 512)['exit'] === 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Asks the helper for the newest release and remembers the answer for the page.
     *
     * @return array<string, mixed>
     */
    public static function check(): array
    {
        self::requireReady();
        $result = BoundedProcess::run([...self::$command, 'check'], '', 90, 65536);
        $answer = json_decode(trim($result['output']), true);
        if ($result['exit'] !== 0 || !is_array($answer)) {
            throw new RuntimeException(self::reason($result, 'The update check did not answer.'));
        }
        if (empty($answer['ok'])) {
            throw new RuntimeException((string) ($answer['error'] ?? 'The update check failed.'));
        }
        $check = [
            'at' => date('Y-m-d H:i'),
            'current' => (string) ($answer['current'] ?? ''),
            'latest' => (string) ($answer['latest'] ?? ''),
            'newer' => !empty($answer['newer']),
            'tag' => (string) ($answer['tag'] ?? ''),
            'notes' => mb_substr((string) ($answer['notes'] ?? ''), 0, 6000),
            'problem' => (string) ($answer['problem'] ?? ''),
        ];
        Settings::save(self::CHECK, json_encode($check, JSON_THROW_ON_ERROR));
        return $check;
    }

    /**
     * The last check, or null; "newer" turns false once the installed version has caught up with it.
     *
     * @return array{at:string,current:string,latest:string,newer:bool,tag:string,notes:string,problem:string}|null
     */
    public static function lastCheck(): ?array
    {
        $saved = json_decode(Settings::value(self::CHECK, ''), true);
        if (!is_array($saved)) {
            return null;
        }
        $check = ['newer' => !empty($saved['newer'])];
        foreach (['at', 'current', 'latest', 'tag', 'notes', 'problem'] as $key) {
            $check[$key] = is_scalar($saved[$key] ?? null) ? (string) $saved[$key] : '';
        }
        if ($check['newer'] && !version_compare($check['latest'], self::current(), '>')) {
            $check['newer'] = false;
        }
        return $check;
    }

    /** Starts the update to the release found by the last check. */
    public static function start(): string
    {
        self::requireReady();
        $check = self::lastCheck();
        if (!$check || !$check['newer'] || !preg_match('/^v\d+\.\d+\.\d+$/D', $check['tag'])) {
            throw new InvalidArgumentException('Check for updates first: there is no newer release to install.');
        }
        if ($check['problem'] !== '') {
            throw new InvalidArgumentException($check['problem']);
        }
        if (self::running()) {
            throw new InvalidArgumentException('An update is already running.');
        }
        $result = BoundedProcess::run([...self::$command, 'schedule', $check['tag']], '', 30, 8192);
        if ($result['exit'] !== 0) {
            throw new RuntimeException(self::reason($result, 'The update could not be started.'));
        }
        return $check['latest'];
    }

    public static function current(): string
    {
        return (string) config('app.version');
    }

    /** @return array{state:string,step:string,message:string,from:string,to:string,at:string}|null the last update's result */
    public static function status(): ?array
    {
        $status = is_readable(self::$statusFile) ? json_decode((string) file_get_contents(self::$statusFile), true) : null;
        if (!is_array($status)) {
            return null;
        }
        $status = array_map('strval', array_intersect_key($status + array_fill_keys(['state', 'step', 'message', 'from', 'to', 'at'], ''), array_flip(['state', 'step', 'message', 'from', 'to', 'at'])));
        // A run that ended without writing its result (the server restarted mid-way) is not "running".
        if ($status['state'] === 'running' && !self::running()) {
            $status['state'] = 'failed';
            $status['message'] = 'The update stopped without finishing. See the log.';
        }
        return $status;
    }

    /** @return list<string> the last lines the helper logged (newest last) */
    public static function log(int $lines = 14): array
    {
        $size = is_readable(self::$logFile) ? (int) @filesize(self::$logFile) : 0;
        $text = $size ? (string) @file_get_contents(self::$logFile, false, null, max(0, $size - 12288)) : '';
        $rows = array_values(array_filter(array_map('rtrim', explode("\n", $text)), static fn ($l) => trim($l) !== ''));
        return array_slice($rows, -$lines);
    }

    private static function requireReady(): void
    {
        if (!self::ready()) {
            throw new RuntimeException('Updates are not enabled on this server. Run ops/apply-service-control.sh as root first.');
        }
    }

    /** @param array<string, mixed> $result BoundedProcess::run() */
    private static function reason(array $result, string $fallback): string
    {
        $detail = trim((string) ($result['error'] ?? '')) ?: trim((string) ($result['output'] ?? ''));
        if (str_contains($detail, 'password is required') || str_contains($detail, 'not allowed')) {
            return 'www-data is not allowed to run the update helper. Run ops/apply-service-control.sh as root.';
        }
        return $detail !== '' ? mb_substr($detail, 0, 300) : $fallback;
    }
}
