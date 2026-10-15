<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class Diagnostics
{
    /** Logs an error without message, trace arguments or request data (they can contain secrets). */
    public static function report(\Throwable $error): string
    {
        $id = bin2hex(random_bytes(12));
        $method = app()->runningInConsole() ? 'cli' : request()->method();
        Log::error('application_error', [
            'request_id' => $id,
            'exception' => get_class($error),
            'code' => (string) $error->getCode(),
            'file' => basename($error->getFile()),
            'line' => $error->getLine(),
            'method' => in_array($method, ['GET', 'POST', 'HEAD', 'cli'], true) ? $method : 'other',
        ]);
        return $id;
    }
}
