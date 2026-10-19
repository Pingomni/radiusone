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
            ->where('backups.s3Providers.b2', 'Backblaze B2')
            ->where('backups.s3Providers.r2', 'Cloudflare R2')
            ->missing('backups.s3Providers.other')
            ->missing('backups.types.gdrive')
            ->where('backups.keepOptions.1', '1 copy'));
        $this->post('/settings/backups/passphrase', ['passphrase' => 'short', 'passphrase_confirmation' => 'short'])->assertSessionHasErrors('passphrase');
        $this->post('/settings/backups/passphrase', ['passphrase' => 'correct horse battery', 'passphrase_confirmation' => 'correct horse batterY'])
            ->assertSessionHas('flash.message', 'The two passphrases do not match.');
        $this->post('/settings/backups/passphrase', ['passphrase' => 'correct horse battery', 'passphrase_confirmation' => 'correct horse battery'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('correct horse battery', BackupDestinations::passphrase());
        $this->post('/settings/backups/local', ['days' => '7'])->assertSessionHas('flash.type', 'success');
        $this->assertSame(7, BackupDestinations::localDays());

        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time() - 3600]);
        $this->post('/settings/backups/passphrase', ['passphrase' => 'another long passphrase', 'passphrase_confirmation' => 'another long passphrase'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('another long passphrase', BackupDestinations::passphrase());
        $this->actingAsOperator('admin');
        $this->get('/settings?tab=backups')->assertForbidden();
    }

    public function test_s3_compatible_destination_is_signed_and_tested_without_exposing_the_secret(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'B2', 'enabled' => true, 'keep' => '1', 'provider' => 'b2', 'endpoint' => 'https://S3.eu-central-003.backblazeb2.com/', 'region' => '',
            'bucket' => 'isp.backups', 'prefix' => 'radius', 'access_key' => '0035f1c2a3b4c5d0000000001', 'secret_key' => 's3-secret'])->assertSessionHas('flash.type', 'success');
        $id = BackupDestinations::all()[0]['id'];
        $saved = BackupDestinations::find($id);
        $this->assertSame(['s3.eu-central-003.backblazeb2.com', 'eu-central-003', 1], [$saved['endpoint'], $saved['region'], $saved['keep']]);
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
        $this->post('/settings/backups/destinations/' . $id . '/test')->assertSessionHas('flash.message', 'B2 works: a test file was uploaded, listed and deleted.');
        $this->assertSame(['PUT', 'GET', 'DELETE'], array_column($this->sent, 'method'));
        $this->assertMatchesRegularExpression('#^https://s3\.eu-central-003\.backblazeb2\.com/isp\.backups/radius/frweb-test-[0-9a-f]{8}\.txt$#', $this->sent[0]['url']);
        $this->assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=0035f1c2a3b4c5d0000000001/\d{8}/eu-central-003/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $this->sent[0]['headers']['Authorization']);
        $this->assertStringContainsString('list-type=2&prefix=radius%2Ffrweb-', $this->sent[1]['url']);

        // Cloudflare R2 signs with region "auto".
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'R2', 'enabled' => true, 'keep' => '3', 'provider' => 'r2', 'endpoint' => '0123abcd4567ef890123abcd4567ef89.r2.cloudflarestorage.com',
            'bucket' => 'radius-backups', 'access_key' => '6d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a', 'secret_key' => 'r2-secret'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('auto', BackupDestinations::all()[1]['region']);

        // An endpoint with a path or another scheme is refused.
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'Bad', 'provider' => 'b2', 'endpoint' => 'evil.com/x', 'bucket' => 'b', 'access_key' => 'AKIAEXAMPLEEXAMPLE12', 'secret_key' => 'x'])
            ->assertSessionHas('flash.type', 'danger');
        // The endpoint must be on the chosen provider's domain, and a provider is required.
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'Bad', 'provider' => 'b2', 'endpoint' => 's3.example.com', 'bucket' => 'b', 'access_key' => 'AKIAEXAMPLEEXAMPLE12', 'secret_key' => 'x'])
            ->assertSessionHas('flash.message', 'A Backblaze B2 endpoint ends in .backblazeb2.com. Copy it from the provider (see Documentation → Backups).');
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'Bad', 'provider' => 'other', 'endpoint' => 's3.example.com', 'bucket' => 'b', 'access_key' => 'AKIAEXAMPLEEXAMPLE12', 'secret_key' => 'x'])
            ->assertSessionHas('flash.message', 'Choose the storage provider under Type.');
        $this->assertCount(2, BackupDestinations::all());
    }

    public function test_a_pasted_bucket_address_fills_in_provider_endpoint_bucket_and_name(): void
    {
        $this->actingAsOperator();
        $key = ['type' => 's3', 'enabled' => true, 'access_key' => '6d1e2f3a4b5c6d7e8f9a0b1c', 'secret_key' => 'secret'];
        foreach ([
            ['https://s3.eu-central-003.backblazeb2.com/isp-backups', 'b2', 's3.eu-central-003.backblazeb2.com', 'isp-backups', 'eu-central-003', 'freeradius-web'],
            ['https://0123abcd4567ef890123abcd4567ef89.r2.cloudflarestorage.com/radius/nightly', 'r2', '0123abcd4567ef890123abcd4567ef89.r2.cloudflarestorage.com', 'radius', 'auto', 'nightly'],
            ['s3.eu-central-1.wasabisys.com/isp', 'wasabi', 's3.eu-central-1.wasabisys.com', 'isp', 'eu-central-1', 'freeradius-web'],
            ['https://isp-backups.fsn1.your-objectstorage.com/', 'hetzner', 'fsn1.your-objectstorage.com', 'isp-backups', 'fsn1', 'freeradius-web'],
            ['https://my-space.fra1.digitaloceanspaces.com', 'spaces', 'fra1.digitaloceanspaces.com', 'my-space', 'fra1', 'freeradius-web'],
        ] as [$url, $provider, $endpoint, $bucket, $region, $prefix]) {
            // The chosen provider is only a starting point: the address decides.
            $this->post('/settings/backups/destinations', $key + ['provider' => 'b2', 'url' => $url])->assertSessionHas('flash.type', 'success');
            $saved = BackupDestinations::all()[count(BackupDestinations::all()) - 1];
            $this->assertSame([$provider, $endpoint, $bucket, $region, $prefix, 7, true],
                [$saved['provider'], $saved['endpoint'], $saved['bucket'], $saved['region'], $saved['prefix'], $saved['keep'], $saved['enabled']], $url);
            $this->assertStringEndsWith(' · ' . $bucket, $saved['name']);
        }

        foreach (['https://evil.example.com/bucket', 'https://s3.eu-central-003.backblazeb2.com/'] as $bad) {
            $this->post('/settings/backups/destinations', $key + ['provider' => 'r2', 'url' => $bad])->assertSessionHas('flash.type', 'danger');
        }
        $this->assertCount(5, BackupDestinations::all());

        // A new key ID with the old (blank) secret is refused: they would not match.
        $this->post('/settings/backups/destinations', ['id' => BackupDestinations::all()[0]['id'], 'enabled' => true, 'access_key' => '0055aaaabbbbcccc0000000009', 'secret_key' => ''])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'You changed the key ID'));

        // Editing without the address keeps the bucket; a blank secret keeps the saved one.
        $id = BackupDestinations::all()[0]['id'];
        $this->post('/settings/backups/destinations', ['id' => $id, 'name' => 'Offsite', 'keep' => '30', 'enabled' => false, 'access_key' => '6d1e2f3a4b5c6d7e8f9a0b1c', 'secret_key' => ''])
            ->assertSessionHas('flash.type', 'success');
        $saved = BackupDestinations::find($id);
        $this->assertSame(['Offsite', 'isp-backups', 30, false, 'secret'], [$saved['name'], $saved['bucket'], $saved['keep'], $saved['enabled'], $saved['secret_key']]);
    }

    public function test_backblaze_needs_only_the_key(): void
    {
        $this->actingAsOperator();
        $this->sent = [];
        $responses = [[200, json_encode(['s3ApiUrl' => 'https://s3.us-west-004.backblazeb2.com', 'allowed' => ['bucketName' => 'isp-backups']])]];
        ProviderHttp::fake(function (string $method, string $url, array $headers, $body) use (&$responses): array {
            $this->sent[] = compact('method', 'url', 'headers', 'body');
            return array_shift($responses) ?? [500, ''];
        });
        $this->post('/settings/backups/destinations', ['type' => 's3', 'enabled' => true, 'provider' => 'b2', 'url' => '', 'access_key' => '0035f1c2a3b4c5d0000000001', 'secret_key' => 'app-key'])
            ->assertSessionHas('flash.type', 'success');
        $this->assertSame('https://api.backblazeb2.com/b2api/v2/b2_authorize_account', $this->sent[0]['url']);
        $this->assertSame('Basic ' . base64_encode('0035f1c2a3b4c5d0000000001:app-key'), $this->sent[0]['headers']['Authorization']);
        $saved = BackupDestinations::all()[0];
        $this->assertSame(['Backblaze B2 · isp-backups', 's3.us-west-004.backblazeb2.com', 'isp-backups', 'us-west-004'], [$saved['name'], $saved['endpoint'], $saved['bucket'], $saved['region']]);

        // A key for the whole account with one bucket finds it; with several, the operator is asked to choose.
        $responses = [
            [200, json_encode(['s3ApiUrl' => 'https://s3.eu-central-003.backblazeb2.com', 'apiUrl' => 'https://api003.backblazeb2.com', 'authorizationToken' => 'tok', 'accountId' => 'acc', 'allowed' => ['bucketName' => null]])],
            [200, json_encode(['buckets' => [['bucketName' => 'a-backups'], ['bucketName' => 'b-photos']]])],
        ];
        $this->post('/settings/backups/destinations', ['type' => 's3', 'provider' => 'b2', 'access_key' => '0035f1c2a3b4c5d0000000002', 'secret_key' => 'app-key'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, '2 buckets (a-backups, b-photos)'));
        $this->assertSame('https://api003.backblazeb2.com/b2api/v2/b2_list_buckets', $this->sent[2]['url']);
        $this->assertSame('tok', $this->sent[2]['headers']['Authorization']);

        // The master key (keyID = 12-character account ID) passes Backblaze's own API but not S3 uploads.
        $calls = count($this->sent);
        $this->post('/settings/backups/destinations', ['type' => 's3', 'provider' => 'b2', 'access_key' => 'b771a2c3d4e5', 'secret_key' => 'master-key'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'master application key'));
        $this->post('/settings/backups/destinations', ['type' => 's3', 'provider' => 'b2', 'url' => 'https://s3.us-east-005.backblazeb2.com/radiusweb-gn', 'access_key' => 'b771a2c3d4e5', 'secret_key' => 'master-key'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'master application key'));
        $this->assertCount($calls, $this->sent);

        $responses = [[401, json_encode(['message' => 'bad key'])]];
        $this->post('/settings/backups/destinations', ['type' => 's3', 'provider' => 'b2', 'access_key' => '0035f1c2a3b4c5d0000000003', 'secret_key' => 'wrong'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Backblaze did not accept this key'));
        $this->assertCount(1, BackupDestinations::all());
    }

    public function test_sftp_server_must_be_user_at_host(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/backups/destinations', ['type' => 'sftp', 'server' => 'backup@nas example'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'user@host'));
        $this->assertSame([], BackupDestinations::all());
    }

    public function test_retired_google_drive_and_amazon_only_destinations_are_dropped(): void
    {
        $this->actingAsOperator();
        \App\Support\Settings::save('backup_destinations', json_encode([
            ['id' => 'aaaaaaaaaaaa', 'type' => 'gdrive', 'name' => 'Drive', 'enabled' => true, 'keep' => 7],
            ['id' => 'bbbbbbbbbbbb', 'type' => 's3', 'name' => 'AWS', 'enabled' => true, 'keep' => 7, 'region' => 'eu-central-1', 'bucket' => 'b'],
        ]), true);
        $this->assertSame([], BackupDestinations::all());
        $this->get('/settings/backups/destinations/aaaaaaaaaaaa/google')->assertNotFound();
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
        // One field for the server; the name and copies to keep take their defaults.
        $user = posix_getpwuid(posix_geteuid())['name'];
        $this->post('/settings/backups/destinations', ['type' => 'sftp', 'enabled' => true, 'server' => $user . '@127.0.0.1:' . $port, 'path' => $remote])->assertSessionHas('flash.type', 'success');
        $destination = BackupDestinations::all()[0];
        $this->assertSame([$user . '@127.0.0.1', '127.0.0.1', (string) $port, $user, 7, true],
            [$destination['name'], $destination['host'], $destination['port'], $destination['user'], $destination['keep'], $destination['enabled']]);
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
        $this->post('/settings/backups/destinations', ['type' => 's3', 'name' => 'B2', 'enabled' => true, 'keep' => '7', 'provider' => 'b2', 'endpoint' => 's3.eu-central-003.backblazeb2.com',
            'bucket' => 'isp-backups', 'access_key' => '0035f1c2a3b4c5d0000000001', 'secret_key' => 's3-secret']);
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
