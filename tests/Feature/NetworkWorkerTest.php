<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CertifiedNetworkDriver;
use App\Support\Db;
use App\Support\NetworkOperations;
use App\Support\RadiusRepository;
use Tests\TestCase;

/** Customers without an access profile: a policy change disconnects them so they reconnect with it. */
final class NetworkWorkerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = getenv('FRWEB_TEST_DIR') . '/network-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->env('SECURITY_STORAGE', $this->dir);
        $this->env('RADCLIENT_BIN', $this->dir . '/radclient');
        $this->radclient(0, 'Received Disconnect-ACK Id 1');
    }

    protected function tearDown(): void
    {
        foreach (['SECURITY_STORAGE', 'RADCLIENT_BIN'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    private function env(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /** A stand-in radclient: records its arguments and answers like a NAS would. */
    private function radclient(int $exit, string $output): void
    {
        file_put_contents($this->dir . '/radclient', "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->dir . '/calls') . "\necho " . escapeshellarg($output) . "\nexit $exit\n");
        chmod($this->dir . '/radclient', 0755);
    }

    private function calls(): array
    {
        return is_file($this->dir . '/calls') ? file($this->dir . '/calls', FILE_IGNORE_NEW_LINES) : [];
    }

    /** A plan, a CoA-ready NAS that FreeRADIUS has loaded, and customer alice. */
    private function setUpCustomer(): void
    {
        $this->actingAsOperator();
        $this->post('/services/save', [
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'custom_group_attrs' => '',
        ])->assertSessionHas('flash.type', 'success');
        $this->post('/nas/save', ['id' => '', 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'bras-01', 'nasname' => '192.0.2.10', 'secret' => 'radius-secret-1',
            'enabled' => true, 'coa_enabled' => true, 'coa_port' => '3799'])->assertSessionHas('flash.type', 'success');
        RadiusRepository::recordLoadedNasRevisions(time() + 60);
        $this->post('/clients/save', [
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => 'alice', 'password' => 'pppoe-pass-1', 'verified' => '0', 'account_status' => 'enabled',
            'expiry_date' => date('Y-m-d', strtotime('+30 days')), 'service_group' => 'home50', 'framed_ip_address' => '', 'framed_pool' => '',
            'nas_restriction_type' => 'all', 'nas_restriction_value' => '', 'language' => 'en', 'first_name' => 'Alice', 'last_name' => 'Doe', '_active_tab' => 'account',
        ])->assertSessionHas('flash.type', 'success');
    }

    private function online(): void
    {
        Db::exec('INSERT INTO radacct (acctuniqueid, acctsessionid, username, nasipaddress, framedipaddress, acctstarttime, acctupdatetime) VALUES ("u1", "81000001", "alice", "192.0.2.10", "100.64.0.5", NOW() - INTERVAL 1 HOUR, NOW())');
    }

    /** Suspends alice: a new policy revision and a queued "suspend" operation. */
    private function suspend(): array
    {
        $this->post('/clients/alice/suspension', ['action' => 'suspend', 'source' => 'manual']);
        $op = Db::row('SELECT * FROM network_operations WHERE action="suspend" ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($op, 'Suspending queues a network operation.');
        return $op;
    }

    private function work(): array
    {
        $this->env('NETWORK_WORKER_ENABLED', 'true');
        return NetworkOperations::run(new CertifiedNetworkDriver);
    }

    public function test_an_offline_customer_gets_the_change_at_the_next_login(): void
    {
        $this->setUpCustomer();
        $op = $this->suspend();
        $this->work();
        $this->assertSame(['verified', 'no_session_next_login'], array_values(Db::row('SELECT state, result_code FROM network_operations WHERE id=?', [$op['id']])));
        $this->assertSame([], $this->calls());
        $this->assertSame(0, (int) NetworkOperations::lag()['outstanding']);
    }

    public function test_an_online_customer_is_disconnected_to_pick_up_the_change(): void
    {
        $this->setUpCustomer();
        $this->online();
        $op = $this->suspend();
        $this->work();
        $this->assertSame(['verified', 'disconnected_for_new_policy'], array_values(Db::row('SELECT state, result_code FROM network_operations WHERE id=?', [$op['id']])));
        $this->assertCount(1, $this->calls());
        $this->assertStringContainsString('192.0.2.10:3799 disconnect', $this->calls()[0]);
    }

    public function test_a_session_without_recent_accounting_is_not_disconnected(): void
    {
        $this->setUpCustomer();
        // Left open in accounting months ago (the NAS never sent a stop): not really online.
        Db::exec('INSERT INTO radacct (acctuniqueid, acctsessionid, username, nasipaddress, acctstarttime, acctupdatetime) VALUES ("old", "81000009", "alice", "192.0.2.10", NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 98 DAY)');
        $op = $this->suspend();
        $this->work();
        $this->assertSame(['verified', 'no_session_next_login'], array_values(Db::row('SELECT state, result_code FROM network_operations WHERE id=?', [$op['id']])));
        $this->assertSame([], $this->calls());
    }

    public function test_a_refused_disconnect_is_retried_then_left_for_review(): void
    {
        $this->setUpCustomer();
        $this->online();
        $this->radclient(1, 'Received Disconnect-NAK Id 1');
        $op = $this->suspend();
        $this->work();
        $row = Db::row('SELECT state, result_code, attempts FROM network_operations WHERE id=?', [$op['id']]);
        $this->assertSame('uncertain', $row['state']);
        $this->assertStringStartsWith('disconnect_retry: Disconnect not acknowledged', $row['result_code']);
        $this->assertSame(1, (int) $row['attempts']);

        // Four more refusals: given up and left for review.
        for ($i = 0; $i < 4; $i++) {
            Db::exec('UPDATE network_operations SET available_at = NOW() WHERE id=?', [$op['id']]);
            $this->work();
        }
        $this->assertSame(['failed', 'disconnect_failed_manual_review'], array_values(Db::row('SELECT state, result_code FROM network_operations WHERE id=?', [$op['id']])));
        $this->assertCount(5, $this->calls());
    }
}
