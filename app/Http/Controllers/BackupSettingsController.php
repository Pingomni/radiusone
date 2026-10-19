<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\App;
use App\Support\Audit;
use App\Support\Backup\BackupDestinations;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\S3Target;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Settings → Backups: passphrase, local retention and remote destinations (S3-compatible storage, SFTP). */
final class BackupSettingsController extends Controller
{
    private const PAGE = '/settings?tab=backups';

    /** Props for the Backups tab (SettingsController::settings). */
    public static function props(): array
    {
        $dir = self::directory();
        $local = [];
        foreach (array_merge(glob($dir . '/freeradius-web-*.sql.gz') ?: [], glob($dir . '/documents-*.tar.gz') ?: []) as $file) {
            $local[] = ['name' => basename($file), 'size' => (int) filesize($file), 'at' => date('Y-m-d H:i', (int) filemtime($file))];
        }
        usort($local, static fn ($a, $b) => strcmp($b['at'], $a['at']));
        return [
            'passphraseSet' => BackupDestinations::passphrase() !== '',
            'localDays' => BackupDestinations::localDays(),
            'localDaysOptions' => BackupDestinations::LOCAL_DAYS,
            'destinations' => BackupDestinations::forBrowser(),
            'types' => BackupDestinations::TYPES,
            'keepOptions' => BackupDestinations::KEEP,
            'status' => BackupRunner::lastStatus($dir),
            'local' => array_slice($local, 0, 12),
            'localCount' => count($local),
            's3Providers' => S3Target::PROVIDERS,
        ];
    }

    public function passphrase(Request $request): RedirectResponse
    {
        try {
            BackupDestinations::setPassphrase((string) $request->input('passphrase', ''), (string) $request->input('passphrase_confirmation', ''));
        } catch (InvalidArgumentException $e) {
            return $this->failKeepingInput($e, self::PAGE, ['passphrase' => '/./']);
        }
        Audit::log('update', 'setting', 'backup_passphrase');
        return $this->ok(self::PAGE, 'Backup passphrase saved. Keep a copy somewhere safe outside this server: without it, remote backups cannot be restored.');
    }

    public function local(Request $request): RedirectResponse
    {
        try {
            BackupDestinations::setLocalDays((int) $request->input('days', 0));
        } catch (InvalidArgumentException $e) {
            return $this->failKeepingInput($e, self::PAGE);
        }
        Audit::log('update', 'setting', 'backup_local_days', null, ['days' => BackupDestinations::localDays()]);
        return $this->ok(self::PAGE, 'Local backup retention saved.');
    }

    public function save(Request $request): RedirectResponse
    {
        $input = $this->formInput($request);
        try {
            $id = BackupDestinations::save($input);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->failKeepingInput($e, self::PAGE);
        }
        $saved = BackupDestinations::find($id);
        Audit::log(empty($input['id']) ? 'create' : 'update', 'backup_destination', $id, null, ['name' => $saved['name'], 'type' => $saved['type'], 'enabled' => $saved['enabled']]);
        $next = match (true) {
            $saved['type'] === 'sftp' && empty($input['id']) => ' Next: add the public key shown below to the server, then use Test.',
            default => ' Use Test to check it.',
        };
        return $this->ok(self::PAGE . '&destination=' . $id, 'Destination saved.' . $next);
    }

    public function delete(string $id): RedirectResponse
    {
        $destination = BackupDestinations::find($id);
        if ($destination === null) {
            return $this->fail('That destination no longer exists.', self::PAGE);
        }
        BackupDestinations::remove($id);
        Audit::log('delete', 'backup_destination', $id, ['name' => $destination['name'], 'type' => $destination['type']]);
        return $this->ok(self::PAGE, 'Destination removed. Backups already stored there were not deleted.');
    }

    public function test(string $id): RedirectResponse
    {
        $destination = BackupDestinations::find($id);
        if ($destination === null) {
            return $this->fail('That destination no longer exists.', self::PAGE);
        }
        try {
            BackupDestinations::test($destination);
        } catch (Throwable $e) {
            return $this->fail('Test failed for ' . $destination['name'] . ': ' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e)), self::PAGE . '&destination=' . $id);
        }
        return $this->ok(self::PAGE . '&destination=' . $id, $destination['name'] . ' works: a test file was uploaded, listed and deleted.');
    }

    private static function directory(): string
    {
        return rtrim((string) App::env('BACKUP_PATH', App::root('var/backups')), '/');
    }
}
