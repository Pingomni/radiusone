<?php

declare(strict_types=1);

namespace App\Support;

final class Audit
{
    public static function log(string $action, string $entityType, ?string $entityId = null, mixed $before = null, mixed $after = null): void
    {
        $user = Auth::user();
        Db::exec(
            'INSERT INTO audit_logs (actor_id, actor_username, action, entity_type, entity_id, ip_address, user_agent, before_json, after_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                $action,
                $entityType,
                $entityId,
                Security::clientIp(),
                substr(app()->runningInConsole() ? 'cli' : (string) request()->userAgent(), 0, 500),
                $before === null ? null : json_encode(self::redact($before), JSON_UNESCAPED_SLASHES),
                $after === null ? null : json_encode(self::redact($after), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            if (str_contains($name, 'password') || str_contains($name, 'secret') || str_contains($name, 'token') || str_contains($name, 'csrf')) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = self::redact($item);
        }

        return $redacted;
    }
}
