<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Backup\BackupCrypto;
use App\Support\Backup\BackupDestinations;
use App\Support\Backup\BackupRunner;
use App\Support\ProviderHttp;
use Tests\TestCase;

final class BackupsTest extends TestCase
{
    private array $sent = [];
    private ?string $sshd = null;
    private string $work = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = getenv('FRWEB_TEST_DIR') . '/backups-' . bin2hex(random_bytes(4));
        mkdir($this->work . '/local', 0700, true);
    }

    protected function tearDown(): void
    {
        ProviderHttp::fake(null);
        unset($_ENV['BACKUP_PATH'], $_SERVER['BACKUP_PATH']);
        if ($this->sshd !== null && is_file($this->sshd . '/sshd.pid')) {
            posix_kill((int) file_get_contents($this->sshd . '/sshd.pid'), SIGTERM);
        }
        exec('rm -rf ' . escapeshellarg($this->work));
        parent::tearDown();
    }

    private function provider(array ...$responses): void
    {
        $this->sent = [];
        ProviderHttp::fake(function (string $method, string $url, array $headers, $body) use (&$responses): array {
            $this->sent[] = compact('method', 'url', 'headers', 'body');
            return array_shift($responses) ?? [500, ''];
        });
    }

    /** The private test database (tests/run.sh), never the server's. */
    private function testDatabase(): array
    {
        return ['DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_DATABASE' => 'frweb_test', 'DB_SOCKET' => (string) getenv('DB_SOCKET')];
    }

    public function test_passphrase_and_local_retention_settings(): void
    {
        $user = $this->actingAsOperator();
        $this->get('/settings?tab=backups')->assertOk()->assertInertia(fn ($page) => $page
            ->where('backups.passphraseSet', false)
            ->where('backups.destinations', [])
            ->where('backups.localDays', 30)
            ->where('backups.googleRedirect', 'https://frweb.test/settings/backups/google/callback'));
        $this->post('/settings/backups/passphrase', ['passphrase' => 'short', 'passphrase_confirmation' => 'short'])->assertSessionHasErrors('passphrase');
        $this->post('/settings/backups/passphrase', ['passphrase' => 'correct horse battery', 'passphrase_confirmation' => 'correct horse batterY'])
            ->assertSessionHas('flash.message', 'The two passphrases do not match.');
        $this->post('/settings/backups/passphrase', ['passphrase' => 'correct horse battery', 'passphrase_confirmation' => 'correct horse battery'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('correct horse battery', BackupDestinations::passphrase());
        $this->post('/settings/backups/local', ['days' => '7'])->assertSessionHas('flash.type', 'success');
        $this->assertSame(7, BackupDestinations::localDays());

        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time() - 3600]);
        $this->post('/settings/backups/passphrase', ['passphrase' => 'another long passphrase', 'passphrase_confirmation' => 'another long passphrase'])->assertRedirect('/reauth');
        $this->actingAsOperator('admin');
        $this->get('/settings?tab=backups')->assertForbidden();
    }

    public function test_s3_destination_is_signed_and_tested_without_exposing_the_secret(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'AWS', 'enabled' => true, 'keep' => '14', 'region' => 'eu-central-1',
            'bucket' => 'isp.backups', 'prefix' => 'radius', 'access_key' => 'AKIAEXAMPLEEXAMPLE12', 'secret_key' => 's3-secret'])->assertSessionHas('flash.type', 'success');
        $id = BackupDestinations::all()[0]['id'];
        $this->get('/settings?tab=backups')->assertInertia(fn ($page) => $page
            ->where('backups.destinations.0.secret_key_saved', true)
            ->missing('backups.destinations.0.secret_key')
            ->where('backups.destinations.0.connected', true));

        $this->provider([200, ''], [200, ''], [204, '']);
        ProviderHttp::fake(function (string $method, string $url, array $headers, $body): array {
            $this->sent[] = compact('method', 'url', 'headers', 'body');
            if ($method === 'GET') {
                $name = basename(parse_url($this->sent[0]['url'], PHP_URL_PATH));
                return [200, '<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>radius/' . $name . '</Key></Contents></ListBucketResult>'];
            }
            return [$method === 'PUT' ? 200 : 204, ''];
        });
        $this->post('/settings/backups/destinations/' . $id . '/test')->assertSessionHas('flash.message', 'AWS works: a test file was uploaded, listed and deleted.');
        $this->assertSame(['PUT', 'GET', 'DELETE'], array_column($this->sent, 'method'));
        $this->assertMatchesRegularExpression('#^https://s3\.eu-central-1\.amazonaws\.com/isp\.backups/radius/frweb-test-[0-9a-f]{8}\.txt$#', $this->sent[0]['url']);
        $this->assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=AKIAEXAMPLEEXAMPLE12/\d{8}/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $this->sent[0]['headers']['Authorization']);
        $this->assertStringContainsString('list-type=2&prefix=radius%2Ffrweb-', $this->sent[1]['url']);

        // Hosts other than S3 are refused even with a crafted region.
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'Bad', 'region' => 'evil.com/x', 'bucket' => 'b', 'access_key' => 'AKIAEXAMPLEEXAMPLE12', 'secret_key' => 'x'])
            ->assertSessionHas('flash.type', 'danger');
    }

    public function test_google_drive_connects_through_oauth_and_uploads_with_a_resumable_session(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/backups/destinations', ['type' => 'gdrive', 'name' => 'Drive', 'enabled' => true, 'keep' => '7',
            'client_id' => '1234-abc.apps.googleusercontent.com', 'client_secret' => 'g-secret', 'folder' => 'ISP backups'])->assertSessionHas('flash.type', 'success');
        $id = BackupDestinations::all()[0]['id'];
        $this->get('/settings?tab=backups')->assertInertia(fn ($page) => $page->where('backups.destinations.0.connected', false));

        $start = $this->get('/settings/backups/destinations/' . $id . '/google');
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $start->headers->get('Location'));
        $this->assertSame(['https://www.googleapis.com/auth/drive.file', 'offline', 'https://frweb.test/settings/backups/google/callback'], [$query['scope'], $query['access_type'], $query['redirect_uri']]);

        $this->get('/settings/backups/google/callback?state=forged&code=x')->assertSessionHas('flash.type', 'danger');
        $start = $this->get('/settings/backups/destinations/' . $id . '/google');
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->provider([200, '{"refresh_token":"r-token","access_token":"a1"}'], [200, '{"access_token":"a2"}'], [200, '{"id":"folder123"}']);
        $this->get('/settings/backups/google/callback?state=' . $query['state'] . '&code=auth-code')->assertSessionHas('flash.type', 'success');
        $this->assertStringContainsString('code=auth-code', $this->sent[0]['body']);
        $this->assertSame(['name' => 'ISP backups', 'mimeType' => 'application/vnd.google-apps.folder'], json_decode($this->sent[2]['body'], true));
        $saved = BackupDestinations::find($id);
        $this->assertSame(['r-token', 'folder123'], [$saved['refresh_token'], $saved['folder_id']]);

        ProviderHttp::fake(function (string $method, string $url, array $headers, $body): array {
            $this->sent[] = compact('method', 'url', 'headers', 'body');
            return match (true) {
                str_contains($url, 'oauth2.googleapis.com/token') => [200, '{"access_token":"a3"}'],
                str_contains($url, 'uploadType=resumable') => [200, '', ['location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=s1']],
                $method === 'PUT' => [200, '{"id":"f1"}'],
                $method === 'GET' => [200, json_encode(['files' => [['id' => 'f1', 'name' => json_decode($this->sent[1]['body'], true)['name']]]])],
                default => [204, ''],
            };
        });
        $this->sent = [];
        $this->post('/settings/backups/destinations/' . $id . '/test')->assertSessionHas('flash.message', 'Drive works: a test file was uploaded, listed and deleted.');
        $this->assertSame(['folder123'], json_decode($this->sent[1]['body'], true)['parents']);
        $this->assertSame('https://www.googleapis.com/drive/v3/files/f1', $this->sent[array_key_last($this->sent)]['url']);
    }

    public function test_nightly_backup_to_sftp_is_encrypted_and_old_copies_are_pruned(): void
    {
        if (!is_executable('/usr/sbin/sshd')) {
            $this->markTestSkipped('sshd is not installed.');
        }
        $this->actingAsOperator();
        $this->post('/settings/backups/passphrase', ['passphrase' => 'correct horse battery', 'passphrase_confirmation' => 'correct horse battery']);
        $port = $this->startSshd();
        $remote = $this->work . '/remote';
        $this->post('/settings/backups/destinations', ['type' => 'sftp', 'name' => 'Office NAS', 'enabled' => true, 'keep' => '7',
            'host' => '127.0.0.1', 'port' => (string) $port, 'user' => posix_getpwuid(posix_geteuid())['name'], 'path' => $remote])->assertSessionHas('flash.type', 'success');
        $destination = BackupDestinations::all()[0];
        $this->assertStringStartsWith('ssh-ed25519 ', $destination['public_key']);
        $this->assertStringContainsString('(ED25519)', $destination['fingerprint']);
        $this->post('/settings/backups/destinations/' . $destination['id'] . '/test')->assertSessionHas('flash.message', fn ($m) => str_contains($m, 'add the public key'));
        file_put_contents($this->sshd . '/authorized_keys', $destination['public_key'] . "\n");
        $this->post('/settings/backups/destinations/' . $destination['id'] . '/test')->assertSessionHas('flash.type', 'success');

        // Nine older copies already on the server: after tonight's upload only the newest seven remain.
        is_dir($remote) || mkdir($remote, 0700, true);
        for ($day = 1; $day <= 9; $day++) {
            touch($remote . sprintf('/frweb-db-202601%02d-021500-aaaa.sql.gz.enc', $day));
        }
        $status = BackupRunner::run($this->work . '/local', $this->testDatabase());
        $this->assertTrue($status['ok'], json_encode($status));
        $this->assertSame(['ok' => true, 'removed' => 3], array_intersect_key($status['destinations'][$destination['id']], ['ok' => 1, 'removed' => 1]));
        $db = glob($remote . '/frweb-db-*.enc');
        $this->assertCount(7, $db);
        $docs = glob($remote . '/frweb-docs-*.enc');
        $this->assertCount(1, $docs);

        // The uploaded copy decrypts to the same dump that stayed on the server.
        $tonight = $remote . '/frweb-db-' . preg_replace('/^freeradius-web-|\.sql\.gz$/', '', $status['database']['file']) . '.sql.gz.enc';
        $this->artisan('frweb:backup-decrypt', ['file' => $tonight, '--out' => $this->work . '/restored.sql.gz'])
            ->expectsQuestion('Backup passphrase', 'correct horse battery')->assertExitCode(0);
        $this->assertSame(sha1_file($this->work . '/local/' . $status['database']['file']), sha1_file($this->work . '/restored.sql.gz'));
        $this->assertStringContainsString('CREATE TABLE `customer_profiles`', (string) gzdecode((string) file_get_contents($this->work . '/restored.sql.gz')));
        $this->artisan('frweb:backup-decrypt', ['file' => $tonight, '--out' => $this->work . '/wrong.sql.gz'])
            ->expectsQuestion('Backup passphrase', 'not the passphrase')->assertExitCode(1);

        // The page reads the status that the nightly run wrote into BACKUP_PATH.
        $_ENV['BACKUP_PATH'] = $_SERVER['BACKUP_PATH'] = $this->work . '/local';
        $this->get('/settings?tab=backups')->assertInertia(fn ($page) => $page
            ->where('backups.status.ok', true)
            ->where('backups.status.destinations.' . $destination['id'] . '.removed', 3)
            ->has('backups.local', 2));
    }

    public function test_no_upload_without_a_passphrase_and_local_copies_are_pruned(): void
    {
        $this->actingAsOperator();
        BackupDestinations::update('x', []);
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'AWS', 'enabled' => true, 'keep' => '7', 'region' => 'eu-central-1',
            'bucket' => 'isp-backups', 'access_key' => 'AKIAEXAMPLEEXAMPLE12', 'secret_key' => 's3-secret']);
        $this->provider();
        $local = $this->work . '/local';
        for ($i = 1; $i <= 6; $i++) {
            touch($local . "/freeradius-web-2026010$i-021500-aaaa.sql.gz", time() - (60 + $i) * 86400);
        }
        $status = BackupRunner::run($local, $this->testDatabase());
        $this->assertFalse($status['ok']);
        $this->assertSame('Set a backup passphrase under Settings → Backups; nothing is uploaded unencrypted.', $status['error']);
        $this->assertSame([], $this->sent);

        BackupDestinations::setLocalDays(30);
        BackupDestinations::update(BackupDestinations::all()[0]['id'], ['enabled' => false]);
        $status = BackupRunner::run($local, $this->testDatabase());
        $this->assertTrue($status['ok'], json_encode($status));
        // Tonight's two dumps plus the newest older one stay; five 60-day-old dumps go.
        $this->assertSame(5, $status['local_removed']);
        $this->assertCount(3, glob($local . '/freeradius-web-*.sql.gz'));
    }

    private function startSshd(): int
    {
        $this->sshd = $this->work . '/sshd';
        mkdir($this->sshd, 0700);
        exec('ssh-keygen -q -t ed25519 -N "" -f ' . escapeshellarg($this->sshd . '/host_key'));
        touch($this->sshd . '/authorized_keys');
        $port = random_int(20000, 29999);
        file_put_contents($this->sshd . '/config', implode("\n", [
            'Port ' . $port, 'ListenAddress 127.0.0.1', 'HostKey ' . $this->sshd . '/host_key', 'PidFile ' . $this->sshd . '/sshd.pid',
            'AuthorizedKeysFile ' . $this->sshd . '/authorized_keys', 'StrictModes no', 'PasswordAuthentication no', 'KbdInteractiveAuthentication no',
            'UsePAM no', 'Subsystem sftp internal-sftp',
        ]) . "\n");
        exec('/usr/sbin/sshd -f ' . escapeshellarg($this->sshd . '/config') . ' -E ' . escapeshellarg($this->sshd . '/log'), $out, $code);
        $this->assertSame(0, $code, 'sshd did not start');
        for ($i = 0; $i < 50 && !is_file($this->sshd . '/sshd.pid'); $i++) {
            usleep(50000);
        }
        return $port;
    }
}
