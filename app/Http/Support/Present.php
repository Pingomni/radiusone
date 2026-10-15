<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\RadiusRepository;

/**
 * Presentation data shared by several pages. A "tag" is ['text' => …, 'tone' => …|null, 'title' => …?];
 * tone null renders as plain text, anything else as a badge (resources/js/components/ui.tsx <Tag>).
 */
final class Present
{
    public static function tag(string $text, ?string $tone = '', ?string $title = null, ?string $href = null): array
    {
        return array_filter(['text' => $text, 'tone' => $tone, 'title' => $title, 'href' => $href], static fn ($v) => $v !== null);
    }

    /** Names the actual disconnect blocker for a NAS row (legacy nasDisconnectBadge). */
    public static function nasDisconnect(array $row): array
    {
        if (empty($row['enabled'])) {
            return self::tag('disconnect off: NAS disabled');
        }
        if (!empty($row['pending_delete'])) {
            return self::tag('removal pending', 'warning');
        }
        if (($row['applied_revision'] ?? null) === null || (int) $row['applied_revision'] !== (int) ($row['config_revision'] ?? 0)) {
            return self::tag('waiting for RADIUS update', 'warning');
        }
        $vendor = strtolower((string) (($row['vendor'] ?? '') ?: ($row['type'] ?? '') ?: ''));
        if ($vendor === 'mikrotik') {
            $api = RadiusRepository::mikrotikDisconnectApiStatus($row);
            return $api['ok'] ? self::tag('RouterOS API', null) : self::tag('API login missing', 'warning');
        }
        return (int) ($row['coa_enabled'] ?? 0) === 1 ? self::tag('CoA', null) : self::tag('CoA off', 'warning');
    }

    /** Key-figure card (resources/js/components/metrics.tsx). */
    public static function metric(string $label, string|int $value, string $icon, string $tone = '', string $href = ''): array
    {
        return ['label' => $label, 'value' => (string) $value, 'icon' => $icon, 'tone' => $tone, 'href' => $href];
    }

    public static function uptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        return ($days ? $days . 'd ' : '') . intdiv($seconds % 86400, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
}
