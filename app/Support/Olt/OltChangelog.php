<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Auth;
use App\Support\Db;
use Throwable;

/**
 * An OLT's changelog: what was done to it from the portal, and what the portal found changed on the OLT itself. The
 * audit log keeps who did what across the whole portal; this is the OLT's own history, kept with the OLT.
 */
final class OltChangelog
{
    /** Kept for this long. */
    public const KEEP_DAYS = 730;
    /** Commands that only read or move around the command line: not changes, so the terminal's are left out. */
    private const NOT_CHANGES = '/^(display|scroll|undo smart|quit|enable|config|interface|return|save|\?)( |$)/i';

    /**
     * Writes one change. A failure here never stops the change itself, which has already happened on the OLT.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function add(int $oltId, string $action, ?string $sn = null, ?string $position = null, array $detail = [], string $source = 'portal', ?string $actor = null): void
    {
        try {
            $actor ??= (string) (Auth::user()['username'] ?? 'system');
            Db::exec('INSERT INTO olt_changes (olt_id,created_at,actor,source,action,sn,position,detail) VALUES (?,NOW(),?,?,?,?,?,?)', [
                $oltId, mb_substr($actor, 0, 80), $source, mb_substr($action, 0, 40), $sn === null ? null : mb_substr($sn, 0, 32),
                $position === null ? null : mb_substr($position, 0, 32), json_encode($detail, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A line typed in the terminal, unless it only reads.
     *
     * @param  array<int, string>  $mode  where on the command line it was typed
     */
    public static function terminal(int $oltId, string $command, array $mode = []): void
    {
        if (trim($command) === '' || preg_match(self::NOT_CHANGES, trim($command))) {
            return;
        }
        self::add($oltId, 'olt_terminal', null, null, ['commands' => [$command], 'mode' => array_values($mode)], 'console');
    }

    /**
     * What changed in the ONU list since the last read, when it was not done from the portal: the portal updates the
     * list itself when it changes an ONU, so only what was done elsewhere (on the OLT, or with another system) differs.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<int, array<string, mixed>>  $before  the ONUs as the portal had them
     * @param  list<array<string, mixed>>  $now  the ONUs as the OLT listed them
     */
    public static function onuChanges(array $olt, array $before, array $now): void
    {
        // The first read of an OLT finds every ONU: that is not a change.
        if (!$before) {
            return;
        }
        $position = static fn (array $o) => $o['frame'] . '/' . $o['slot'] . '/' . $o['port'] . ':' . $o['ont_id'];
        $old = array_column($before, null, 'sn');
        $new = array_column($now, null, 'sn');
        $id = (int) $olt['id'];
        foreach ($new as $sn => $onu) {
            $was = $old[$sn] ?? null;
            if ($was === null) {
                self::add($id, 'onu_found', (string) $sn, $position($onu), ['description' => (string) ($onu['description'] ?? ''), 'model' => (string) ($onu['onu_model'] ?? '')], 'olt', '');
                continue;
            }
            if ($position($was) !== $position($onu)) {
                self::add($id, 'onu_moved', (string) $sn, $position($onu), ['from' => $position($was)], 'olt', '');
            }
            if ((string) $was['description'] !== (string) ($onu['description'] ?? '')) {
                self::add($id, 'onu_renamed', (string) $sn, $position($onu), ['before' => (string) $was['description'], 'after' => (string) ($onu['description'] ?? '')], 'olt', '');
            }
            foreach (['line_profile' => 'line profile', 'service_profile' => 'service profile'] as $key => $what) {
                if ((string) ($was[$key] ?? '') !== '' && (string) ($onu[$key] ?? '') !== '' && (string) $was[$key] !== (string) $onu[$key]) {
                    self::add($id, 'onu_profile', (string) $sn, $position($onu), ['what' => $what, 'before' => (string) $was[$key], 'after' => (string) $onu[$key]], 'olt', '');
                }
            }
            if (($was['active'] ?? null) !== null && ($onu['active'] ?? null) !== null && (int) $was['active'] !== (int) $onu['active']) {
                self::add($id, (int) $onu['active'] ? 'onu_enabled' : 'onu_disabled', (string) $sn, $position($onu), [], 'olt', '');
            }
        }
        foreach ($old as $sn => $was) {
            if (!isset($new[$sn])) {
                self::add($id, 'onu_gone', (string) $sn, $position($was), ['description' => (string) $was['description']], 'olt', '');
            }
        }
    }

    /**
     * The lines that differ between two copies of the configuration, with the OLT's secrets masked.
     *
     * @return array{added:list<string>,removed:list<string>,addedCount:int,removedCount:int}
     */
    public static function configDiff(string $before, string $after): array
    {
        $lines = static fn (string $text) => array_values(array_filter(array_map('rtrim', explode("\n", $text)), static fn (string $l) => trim($l) !== '' && trim($l) !== '#'));
        $old = $lines($before);
        $new = $lines($after);
        // Lines as a multiset: a line that is in both as often counts as unchanged, wherever it moved.
        $count = static function (array $list): array {
            $n = [];
            foreach ($list as $l) {
                $n[$l] = ($n[$l] ?? 0) + 1;
            }
            return $n;
        };
        $oldCount = $count($old);
        $newCount = $count($new);
        $added = [];
        foreach ($new as $l) {
            if (($oldCount[$l] ?? 0) > 0) {
                $oldCount[$l]--;
            } else {
                $added[] = self::mask($l);
            }
        }
        $removed = [];
        foreach ($old as $l) {
            if (($newCount[$l] ?? 0) > 0) {
                $newCount[$l]--;
            } else {
                $removed[] = self::mask($l);
            }
        }
        return ['added' => array_slice($added, 0, 300), 'removed' => array_slice($removed, 0, 300), 'addedCount' => count($added), 'removedCount' => count($removed)];
    }

    /** A configuration line without the secret in it: what follows cipher, password, community or key. */
    public static function mask(string $line): string
    {
        return (string) preg_replace('/\b(cipher|password(?:-auth)?|community|key|secret|pass)(\s+(?:read|write)?\s*(?:cipher\s+)?)("[^"]*"|\S+)/i', '$1$2***', $line);
    }

    /**
     * An OLT's changelog for its page, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOlt(int $oltId, int $limit = 1000): array
    {
        $onus = [];
        foreach (Db::select('SELECT id,sn,description FROM olt_onus WHERE olt_id=?', [$oltId]) as $o) {
            $onus[(string) $o['sn']] = $o;
        }
        return array_values(array_map(static fn (array $r) => self::row($r, $onus), Db::select('SELECT * FROM olt_changes WHERE olt_id=? ORDER BY created_at DESC,id DESC LIMIT ' . max(1, $limit), [$oltId])));
    }

    /**
     * One ONU's history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOnu(int $oltId, string $sn, int $limit = 300): array
    {
        return array_values(array_map(static fn (array $r) => self::row($r, []), Db::select('SELECT * FROM olt_changes WHERE olt_id=? AND sn=? ORDER BY created_at DESC,id DESC LIMIT ' . max(1, $limit), [$oltId, $sn])));
    }

    /** When the ONU was added to the OLT, as far as the portal knows. */
    public static function addedAt(int $oltId, string $sn): ?string
    {
        $at = Db::value('SELECT MIN(created_at) FROM olt_changes WHERE olt_id=? AND sn=? AND action IN ("onu_add","onu_found")', [$oltId, $sn]);
        return $at === null ? null : (string) $at;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, array<string, mixed>>  $onus
     * @return array<string, mixed>
     */
    private static function row(array $r, array $onus): array
    {
        $detail = json_decode((string) ($r['detail'] ?? ''), true);
        $detail = is_array($detail) ? $detail : [];
        $onu = $r['sn'] === null ? null : ($onus[(string) $r['sn']] ?? null);
        $commands = array_values(array_filter((array) ($detail['commands'] ?? []), 'is_string'));
        unset($detail['commands'], $detail['olt']);
        return [
            'id' => (int) $r['id'], 'at' => (string) $r['created_at'], 'actor' => (string) $r['actor'], 'source' => (string) $r['source'], 'action' => (string) $r['action'],
            'sn' => $r['sn'] === null ? null : (string) $r['sn'], 'position' => $r['position'] === null ? null : (string) $r['position'],
            'onuId' => $onu === null ? null : (int) $onu['id'], 'onuName' => $onu === null ? '' : (string) $onu['description'],
            'commands' => $commands, 'detail' => array_filter($detail, static fn ($v) => is_scalar($v) || is_array($v)),
        ];
    }
}
