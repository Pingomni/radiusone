<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\SoftwareUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use RuntimeException;

/** Settings → Overview → Software update: check for a newer release, install it, follow its progress. */
final class SoftwareUpdateController extends Controller
{
    private const PAGE = '/settings';

    /** @return array<string, mixed> */
    public static function props(): array
    {
        return [
            'current' => SoftwareUpdate::current(),
            'ready' => SoftwareUpdate::ready(),
            'running' => SoftwareUpdate::running(),
            'check' => SoftwareUpdate::lastCheck(),
            'status' => SoftwareUpdate::status(),
            'log' => SoftwareUpdate::log(),
        ];
    }

    public function check(): RedirectResponse
    {
        try {
            $check = SoftwareUpdate::check();
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), self::PAGE);
        }
        return $this->ok(self::PAGE, $check['newer'] ? 'Version ' . $check['latest'] . ' is available.' : 'RadiusOne is up to date (' . $check['current'] . ').');
    }

    public function start(): RedirectResponse
    {
        try {
            $version = SoftwareUpdate::start();
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->fail($e->getMessage(), self::PAGE);
        }
        Audit::log('update', 'software', $version, ['version' => SoftwareUpdate::current()], ['version' => $version]);
        return $this->ok(self::PAGE, 'Update to ' . $version . ' started. The portal pauses for a few minutes; this page shows the progress.');
    }

    /** Polled while an update runs; reachable during maintenance mode (bootstrap/app.php). */
    public function status(): JsonResponse
    {
        return response()->json([
            'current' => SoftwareUpdate::current(),
            'running' => SoftwareUpdate::running(),
            'status' => SoftwareUpdate::status(),
            'log' => SoftwareUpdate::log(),
        ])->header('Cache-Control', 'no-store');
    }
}
