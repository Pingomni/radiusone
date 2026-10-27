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
            // Before 2.3's layout (the freeradius-web names): offer "Finish moving to RadiusOne".
            'move' => SoftwareUpdate::needsMove(),
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
        return $this->ok(self::PAGE, 'Installing RadiusOne ' . $version . '. The portal pauses for a few minutes; this page shows the progress.');
    }

    /** Moves this server to the RadiusOne layout (ops/radiusone-upgrade-2.3), from the portal. */
    public function move(): RedirectResponse
    {
        try {
            SoftwareUpdate::move();
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->fail($e->getMessage(), self::PAGE);
        }
        Audit::log('update', 'software', 'move', ['version' => SoftwareUpdate::current()], ['layout' => 'radiusone']);

        return $this->ok(self::PAGE, 'Moving to the RadiusOne layout. The portal pauses for a few minutes; this page shows the progress.');
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
