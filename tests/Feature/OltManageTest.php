<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminAlerts;
use App\Support\Db;
use App\Support\Olt\HuaweiCli;
use App\Support\Olt\HuaweiMa5800;
use App\Support\Olt\OltChangelog;
use App\Support\Olt\OltInventory;
use App\Support\Olt\OltManager;
use App\Support\Olt\OltMonitor;
use App\Support\Olt\Olts;
use App\Support\Olt\OltSync;
use App\Support\Olt\OltTelnet;
use RuntimeException;
use Tests\TestCase;

/** Changing an OLT from the portal, against the pretend OLT in tests/fixtures/fake-olt.php. */
final class OltManageTest extends TestCase
{
    private const BASE = '1.3.6.1.4.1.2011.6.128.1.1.2';
    /** GPON 0/1/0 in the MA5800's numbering. */
    private const PORT_A = 4194312192;

    /** @var resource|null */
    private $agent = null;
    private string $log = '';
    private int $snmpPort = 0;
    private int $telnetPort = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->agent)) {
            proc_terminate($this->agent);
            proc_close($this->agent);
        }
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $more */
    private function startAgent(array $more = [], array $oids = []): void
    {
        $dir = (string) getenv('RADIUSONE_TEST_DIR');
        $this->log = $dir . '/fake-olt-' . bin2hex(random_bytes(4)) . '.log';
        $this->snmpPort = random_int(20000, 40000);
        $this->telnetPort = $this->snmpPort + 1;
        $config = $dir . '/fake-olt-' . bin2hex(random_bytes(4)) . '.json';
        $text = static fn (string $v) => [4, base64_encode($v)];
        $b = self::BASE;
        touch($this->log);
        file_put_contents($config, json_encode($more + [
            'log' => $this->log, 'ready' => $config . '.ready', 'snmp_port' => $this->snmpPort, 'telnet_port' => $this->telnetPort,
            'community' => 'public-ro', 'user' => 'manager', 'password' => 'manage-pass', 'mac_table' => "  Total: 0\n",
            'oids' => $oids + [
                '1.3.6.1.2.1.1.1.0' => $text('Huawei Integrated Access Software MA5800-X7'),
                '1.3.6.1.2.1.2.2.1.2.' . self::PORT_A => $text('GPON 0/1/0'),
                "$b.43.1.3." . self::PORT_A . '.3' => $text('HWTC' . hex2bin('1234ABCD')),
                "$b.43.1.3." . self::PORT_A . '.4' => $text('HWTC' . hex2bin('1234ABCE')),
                "$b.46.1.1." . self::PORT_A . '.3' => [2, 1],
                "$b.46.1.1." . self::PORT_A . '.4' => [2, 2],
                "$b.46.1.15." . self::PORT_A . '.3' => [2, 1],
                "$b.46.1.15." . self::PORT_A . '.4' => [2, 2],
            ],
        ], JSON_THROW_ON_ERROR));
        $pipes = [];
        $this->agent = proc_open([PHP_BINARY, base_path('tests/fixtures/fake-olt.php'), $config], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($config . '.ready'); $i++) {
            usleep(50000);
        }
        $this->assertFileExists($config . '.ready');
    }

    /** @return array<string, mixed> */
    private function olt(bool $manage = true): array
    {
        return Olts::find(Olts::save(null, Olts::validated([
            'name' => 'olt-1', 'model' => 'huawei_ma5800_x7', 'host' => '127.0.0.1', 'enabled' => '1',
            'snmp_version' => '2c', 'snmp_port' => (string) $this->snmpPort, 'snmp_community' => 'public-ro',
            'telnet_enabled' => '1', 'telnet_port' => (string) $this->telnetPort, 'telnet_user' => 'manager', 'telnet_password' => 'manage-pass',
            'manage_enabled' => $manage ? '1' : '',
        ], null)));
    }

    /** The ONU the pretend OLT has at 0/1/0:3, as the portal last read it. */
    private function onu(array $olt, string $sn = 'HWTC1234ABCD', int $ontId = 3): int
    {
        return $this->insertRow('olt_onus', ['olt_id' => $olt['id'], 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => $ontId, 'sn' => $sn, 'description' => 'Kati 2 hyrja A', 'status' => 'online', 'active' => 1, 'seen_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<string> every line the pretend OLT received over telnet after the login */
    private function commands(): array
    {
        $lines = array_filter(file($this->log, FILE_IGNORE_NEW_LINES), static fn (string $l) => str_starts_with($l, 'telnet-cmd '));
        return array_values(array_map(static fn (string $l) => substr($l, 11), $lines));
    }

    /** @return list<string> only the lines that change the OLT */
    private function changes(): array
    {
        return array_values(array_filter($this->commands(), static fn (string $c) => !preg_match('/^(enable|scroll|undo smart|config|quit|y|n|display .*|interface gpon .*)$/', $c)));
    }

    public function test_nothing_is_changed_until_changes_are_allowed_for_the_olt(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        foreach (['reboot', 'disable', 'enable', 'describe', 'delete'] as $action) {
            $this->post("/olts/{$olt['id']}/onus/$onu/$action", ['description' => 'x'])->assertSessionHas('flash.type', 'danger');
        }
        $this->post("/olts/{$olt['id']}/provision", ['sn' => 'HWTCAAAA0001', 'description' => 'x', 'line_profile' => '6', 'service_profile' => '2', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9'])->assertSessionHas('flash.type', 'danger');
        $this->assertSame([], $this->commands());
        $this->assertSame(0, (int) Db::value('SELECT manage_enabled FROM olts'));
    }

    public function test_allowing_changes_needs_telnet(): void
    {
        $values = Olts::validated(['name' => 'x', 'model' => 'huawei_ma5800_x7', 'host' => '192.0.2.10', 'snmp_version' => '2c', 'snmp_port' => '161', 'snmp_community' => 'c', 'telnet_port' => '23', 'manage_enabled' => '1'], null);
        $this->assertSame(0, $values['manage_enabled']);
    }

    public function test_restart_sends_one_command_after_checking_the_serial_number(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/onus/$onu/reboot")->assertSessionHas('flash.type', 'success');
        $this->assertSame(['enable', 'scroll', 'undo smart', 'display ont info 0 1 0 3', 'config', 'interface gpon 0/1', 'ont reset 0 3', 'y', 'quit', 'quit', 'quit', 'y'], $this->commands());
        $audit = Db::row('SELECT * FROM audit_logs WHERE action="onu_reboot"');
        $this->assertSame('HWTC1234ABCD', $audit['entity_id']);
        $this->assertStringContainsString('ont reset 0 3', $audit['after_json']);
        $this->assertNotNull(Db::value('SELECT sync_requested_at FROM olts'));
    }

    public function test_a_different_onu_at_that_position_is_never_touched(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        // The portal's list is stale: it believes another serial number sits at 0/1/0:3.
        $onu = $this->onu($olt, 'HWTC99999999');
        $this->actingAsOperator();
        foreach (['reboot', 'disable', 'delete'] as $action) {
            $this->post("/olts/{$olt['id']}/onus/$onu/$action")->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'different ONU'));
        }
        $this->assertSame([], $this->changes());
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
        // And one the OLT no longer has at all.
        $gone = $this->onu($olt, 'HWTC88888888', 77);
        $this->post("/olts/{$olt['id']}/onus/$gone/reboot")->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'no ONU at 0/1/0:77'));
        $this->assertSame([], $this->changes());
    }

    public function test_turning_an_onu_off_and_on(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/onus/$onu/disable")->assertSessionHas('flash.type', 'success');
        $this->assertSame(['ont deactivate 0 3'], $this->changes());
        $row = Db::row('SELECT * FROM olt_onus');
        $this->assertSame([0, 'offline'], [(int) $row['active'], $row['status']]);
        // A turned off ONU cannot be restarted.
        $this->post("/olts/{$olt['id']}/onus/$onu/reboot")->assertSessionHas('flash.type', 'danger');
        $this->post("/olts/{$olt['id']}/onus/$onu/enable")->assertSessionHas('flash.type', 'success');
        $this->assertSame(['ont deactivate 0 3', 'ont activate 0 3'], $this->changes());
        $this->assertSame(1, (int) Db::value('SELECT active FROM olt_onus'));
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action IN ("onu_disable","onu_enable")'));
    }

    public function test_a_description_is_cleaned_before_it_reaches_the_olt(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/onus/$onu/describe", ['description' => "Ëndrit Çela \"kati 2\"?\r\nont delete 0 3"])->assertSessionHas('flash.type', 'success');
        $this->assertSame(['ont modify 0 3 desc "Endrit Cela kati 2 ont delete 0 3"'], $this->changes());
        $this->assertSame('Endrit Cela kati 2 ont delete 0 3', Db::value('SELECT description FROM olt_onus'));
        $this->post("/olts/{$olt['id']}/onus/$onu/describe", ['description' => '???'])->assertSessionHas('flash.type', 'danger');
        $this->assertCount(1, $this->changes());
        $this->assertSame('Failure x', HuaweiCli::description('Failure: x'));
        $this->assertSame(64, strlen(HuaweiCli::description(str_repeat('a', 200))));
    }

    public function test_removing_an_onu_takes_its_service_ports_first_and_unlinks_its_client(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $customer = $this->insertRow('customer_profiles', ['username' => 'gtest', 'account_status' => 'active', 'enabled' => 1]);
        $this->insertRow('customer_onus', ['customer_id' => $customer, 'olt_id' => $olt['id'], 'sn' => 'HWTC1234ABCD', 'source' => 'manual', 'linked_at' => date('Y-m-d H:i:s')]);
        // NOC may manage, but not delete.
        $this->actingAsOperator('noc');
        $this->post("/olts/{$olt['id']}/onus/$onu/delete")->assertForbidden();
        $this->assertSame([], $this->commands());
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/onus/$onu/delete")->assertRedirect('/olts/' . $olt['id'])->assertSessionHas('flash.type', 'success');
        // Only this ONU's service port: 2101 belongs to another ONU.
        $this->assertSame(['undo service-port 2100', 'ont delete 0 3'], $this->changes());
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM customer_onus'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM customer_profiles'));
    }

    public function test_the_new_onus_page_lists_what_is_plugged_in_and_what_most_onus_use(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->insertRow('customer_profiles', ['username' => 'gtest', 'first_name' => 'Ëndrit', 'last_name' => 'Çela', 'account_status' => 'active', 'enabled' => 1]);
        $this->actingAsOperator();
        $this->get("/olts/{$olt['id']}/provision?client=gtest")->assertOk()->assertInertia(fn ($p) => $p->component('olts/Provision')
            ->where('error', '')
            ->where('options.found.0.sn', 'HWTCAAAA0001')->where('options.found.0.port', 0)->where('options.found.0.model', 'EG8145V5')
            ->where('options.defaults.line_profile', '6')->where('options.defaults.service_profile', '2')->where('options.defaults.vlan', '100')
            ->where('options.defaults.traffic_up', '8')->where('options.defaults.traffic_down', '9')->where('options.defaults.gem', '1')
            ->where('options.defaults.wan_profile', '0')->where('options.defaults.route_profile', '1')
            ->where('client.description', 'Endrit Cela gtest'));
        $this->assertSame([], $this->changes());
    }

    public function test_adding_an_onu_in_router_mode_and_linking_its_client(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->onu($olt);
        $this->insertRow('customer_profiles', ['username' => 'gtest', 'account_status' => 'active', 'enabled' => 1]);
        $this->actingAsOperator();
        $input = ['sn' => 'HWTC-AAAA0001', 'mode' => 'router', 'line_profile' => '6', 'service_profile' => '2', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'wan_profile' => '0', 'route_profile' => '1', 'description' => 'Endrit Cela', 'client' => 'gtest'];
        $this->post("/olts/{$olt['id']}/provision", $input)->assertSessionHas('flash.type', 'success');
        $this->assertSame([
            'ont add 0 sn-auth "48575443AAAA0001" omci ont-lineprofile-id 6 ont-srvprofile-id 2 desc "Endrit Cela"',
            'ont ipconfig 0 0 ip-index 1 dhcp vlan 100 priority 0',
            'ont internet-config 0 0 ip-index 1',
            'ont wan-config 0 0 ip-index 1 profile-id 0',
            'ont policy-route-config 0 0 profile-id 1',
            'ont port route 0 0 eth 1 enable', 'ont port route 0 0 eth 2 enable', 'ont port route 0 0 eth 3 enable', 'ont port route 0 0 eth 4 enable',
            'service-port vlan 100 gpon 0/1/0 ont 0 gemport 1 multi-service user-vlan 100 tag-transform translate inbound traffic-table index 8 outbound traffic-table index 9',
        ], $this->changes());
        $row = Db::row('SELECT * FROM olt_onus WHERE sn="HWTCAAAA0001"');
        $this->assertSame([0, 1, 0, 0, 'Endrit Cela', 'EG8145V5'], [(int) $row['frame'], (int) $row['slot'], (int) $row['port'], (int) $row['ont_id'], $row['description'], $row['onu_model']]);
        $link = Db::row('SELECT * FROM customer_onus');
        $this->assertSame(['HWTCAAAA0001', 'manual'], [$link['sn'], $link['source']]);
        $this->assertSame('100', (string) json_decode((string) Db::value('SELECT provision_defaults FROM olts'), true)['vlan']);
        $this->assertStringContainsString('ont add 0 sn-auth', (string) Db::value('SELECT after_json FROM audit_logs WHERE action="onu_add"'));
        // It is no longer "new", and the form now starts from what was just used without reading every service port.
        file_put_contents($this->log, '');
        $this->get("/olts/{$olt['id']}/provision")->assertInertia(fn ($p) => $p->where('options.found', [])->where('options.defaults.route_profile', '1'));
        $this->assertNotContains('display service-port all', $this->commands());
        // The same ONU cannot be added twice.
        $this->post("/olts/{$olt['id']}/provision", $input)->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'no longer lists'));
        $this->assertSame([], $this->changes());
    }

    public function test_adding_an_onu_in_bridge_mode(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/provision", ['sn' => 'HWTCAAAA0001', 'mode' => 'bridge', 'line_profile' => '6', 'service_profile' => '1', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'wan_profile' => '0', 'description' => 'Bridge'])->assertSessionHas('flash.type', 'success');
        $this->assertSame([
            'ont add 0 sn-auth "48575443AAAA0001" omci ont-lineprofile-id 6 ont-srvprofile-id 1 desc "Bridge"',
            'ont port native-vlan 0 0 eth 1 vlan 100 priority 0',
            'service-port vlan 100 gpon 0/1/0 ont 0 gemport 1 multi-service user-vlan 100 tag-transform translate inbound traffic-table index 8 outbound traffic-table index 9',
        ], $this->changes());
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM customer_onus'));
    }

    public function test_no_onu_is_added_on_a_port_that_has_the_most_onus_allowed(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->onu($olt);
        Db::exec('UPDATE olts SET max_onus_per_pon=1 WHERE id=?', [$olt['id']]);
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/provision", ['sn' => 'HWTCAAAA0001', 'mode' => 'bridge', 'line_profile' => '6', 'service_profile' => '1', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'description' => 'Full'])
            ->assertSessionHas('flash.type', 'danger')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Port 0/1/0 already has 1 ONUs'));
        $this->assertSame([], $this->changes());
        $this->assertThrows(fn () => Olts::validated(['name' => 'x', 'model' => 'huawei_ma5800_x7', 'host' => '127.0.0.1', 'snmp_version' => '2c', 'snmp_community' => 'c', 'max_onus_per_pon' => '200'], null), RuntimeException::class, 'from 1 to 128');
    }

    public function test_an_onu_that_cannot_be_finished_is_taken_off_again(): void
    {
        $this->startAgent(['fail' => ['service-port vlan']]);
        $olt = $this->olt();
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/provision", ['sn' => 'HWTCAAAA0001', 'mode' => 'bridge', 'line_profile' => '6', 'service_profile' => '1', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'description' => 'Half'])
            ->assertSessionHas('flash.type', 'danger')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Made to fail by the test') && str_contains($m, 'taken off the OLT again'));
        $changes = $this->changes();
        $this->assertSame('ont delete 0 0', end($changes));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
        $this->assertNull(Db::value('SELECT provision_defaults FROM olts'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="onu_add"'));
        // A failure in the middle of the ONU's own settings is undone the same way.
        $this->tearDown();
        $this->setUp();
        $this->startAgent(['fail' => ['ont port native-vlan']]);
        $olt = $this->olt();
        try {
            OltManager::authorize($olt, ['sn' => 'HWTCAAAA0001', 'mode' => 'bridge', 'line_profile' => '6', 'service_profile' => '1', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'description' => 'Half']);
            $this->fail('Added');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('taken off the OLT again', $e->getMessage());
        }
        $this->assertSame(['ont add 0 sn-auth "48575443AAAA0001" omci ont-lineprofile-id 6 ont-srvprofile-id 1 desc "Half"', 'ont port native-vlan 0 0 eth 1 vlan 100 priority 0', 'ont delete 0 0'], $this->changes());
    }

    public function test_bad_choices_never_reach_the_olt(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $good = ['sn' => 'HWTCAAAA0001', 'mode' => 'router', 'line_profile' => '6', 'service_profile' => '2', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'description' => 'x'];
        foreach ([['sn' => 'nope'], ['line_profile' => '6 ont-type 10g'], ['vlan' => '0'], ['vlan' => '100 inbound'], ['gem' => '-1'], ['traffic_up' => ''], ['description' => '"?"'], ['wan_profile' => 'x']] as $bad) {
            try {
                OltManager::authorize($olt, $bad + $good);
                $this->fail('Accepted ' . json_encode($bad));
            } catch (RuntimeException) {
            }
        }
        $this->assertSame([], $this->commands());
    }

    public function test_live_details_only_read(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $onu = $this->onu($olt);
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/onus/$onu")->assertOk()->assertInertia(fn ($p) => $p->component('olts/Onu')->where('onu.sn', 'HWTC1234ABCD')->where('olt.canChange', false)->where('olt.canRead', true));
        $this->get("/olts/{$olt['id']}/onus/$onu/live")->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('data.state.run', 'online')->assertJsonPath('data.state.active', true)
            ->assertJsonPath('data.optical.rx', '-18.18')->assertJsonPath('data.optical.oltRx', '-22.45')
            ->assertJsonPath('data.wans.0.ip', '10.200.0.254')->assertJsonPath('data.wans.0.status', 'Connected')
            ->assertJsonPath('data.lan.1.up', true)->assertJsonPath('data.lan.1.speed', '100')
            ->assertJsonPath('data.software.version', 'V5R024C10S112');
        $this->assertSame([], $this->changes());
        $stale = $this->onu($olt, 'HWTC99999999', 77);
        $this->get("/olts/{$olt['id']}/onus/$stale/live")->assertOk()->assertJsonPath('ok', false);
    }

    public function test_permissions(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/provision")->assertForbidden();
        $this->post("/olts/{$olt['id']}/provision", [])->assertForbidden();
        foreach (['reboot', 'disable', 'enable', 'describe', 'delete'] as $action) {
            $this->post("/olts/{$olt['id']}/onus/$onu/$action")->assertForbidden();
        }
        $this->post("/olts/{$olt['id']}/onus/$onu/save")->assertNotFound();
        $this->assertSame([], $this->commands());
    }

    public function test_a_question_the_portal_did_not_expect_is_answered_no(): void
    {
        $this->startAgent();
        $t = new OltTelnet('127.0.0.1', $this->telnetPort, 'manager', 'manage-pass', 30.0);
        $t->open();
        $t->command('config');
        $t->command('interface gpon 0/1');
        try {
            $t->command('ont reset 0 3');
            $this->fail('Answered yes');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing was done', $e->getMessage());
        }
        foreach (['display ont info 0 1 0 3?', "quit\nont delete 0 3", "a\tb", ''] as $bad) {
            try {
                $t->command($bad);
                $this->fail('Sent ' . json_encode($bad));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot be sent', $e->getMessage());
            }
        }
        $t->close();
        $this->assertSame(['enable', 'config', 'interface gpon 0/1', 'ont reset 0 3', 'n', 'quit', 'quit', 'quit', 'y'], $this->commands());
    }

    public function test_the_reader_knows_an_onu_that_is_turned_off(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        OltSync::run($olt);
        $this->assertSame(1, (int) Db::value('SELECT last_sync_ok FROM olts'));
        $this->assertSame(['HWTC1234ABCD' => 1, 'HWTC1234ABCE' => 0], array_map('intval', array_column(Db::select('SELECT sn,active FROM olt_onus ORDER BY sn'), 'active', 'sn')));
        $this->actingAsOperator();
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->where('result.rows.1.active', false)->where('olt.canChange', false));
    }

    /** The two ports the pretend OLT's command line knows, as the SNMP read would have left them. */
    private function ports(array $olt): void
    {
        foreach ([[self::PORT_A, 'GPON 0/1/0', 'pon'], [7, 'ethernet0/9/0', 'uplink']] as [$index, $name, $kind]) {
            $this->insertRow('olt_ports', ['olt_id' => $olt['id'], 'if_index' => $index, 'name' => $name, 'kind' => $kind, 'admin_up' => 1, 'oper_up' => 1, 'sampled_at' => time()]);
        }
    }

    public function test_the_inventory_is_read_from_the_command_line_with_reads_only(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->ports($olt);
        $this->onu($olt);
        $inventory = OltInventory::read($olt);
        $this->assertSame(['software' => 'MA5800V100R018C00', 'patch' => 'SPH612', 'product' => 'MA5800-X7'], $inventory['version']);
        $this->assertSame([1, 100], array_column($inventory['vlans'], 'id'));
        $this->assertSame(['type' => 'GE', 'online' => true, 'vlans' => [1, 100, 200]], array_intersect_key($inventory['uplinks']['0/9/0'], array_flip(['type', 'online', 'vlans'])));
        $this->assertSame([7.12, true, 20, 'online'], [$inventory['pon']['0/1/0']['tx'], $inventory['pon']['0/1/0']['autofind'], $inventory['pon']['0/1/0']['max_km'], $inventory['pon']['0/1/0']['state']]);
        $this->assertSame(['on' => true, 'minutes' => 60], $inventory['autosave']);
        $this->assertSame('192.0.2.123', $inventory['ntp'][0]['server']);
        // The new ONU on the fibre that nobody has added yet.
        $this->assertSame(['sn' => 'HWTCAAAA0001', 'position' => '0/1/0'], array_intersect_key($inventory['waiting'][0], array_flip(['sn', 'position'])));
        $this->assertSame([], $this->changes());
        $this->assertNotContains('display current-configuration', $this->commands());

        $this->actingAsOperator('view');
        $this->get('/olts/' . $olt['id'])->assertOk()->assertInertia(fn ($p) => $p
            ->where('details.version.product', 'MA5800-X7')->where('details.autosave.minutes', 60)->where('details.vlans.1.id', 100)
            ->where('hardware.ports.0.kind', 'uplink')->where('hardware.ports.0.uplink.vlans', [1, 100, 200])
            ->where('hardware.ports.1.pon.tx', 7.12)->where('hardware.ports.1.pon.autofind', true)->where('hardware.ports.1.onus.total', 1)
            // Configuration copies are not for people who may only look.
            ->where('backups', null));
    }

    public function test_a_rogue_onu_is_shown_logged_and_alerted_until_it_clears(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->ports($olt);
        // The last reading saw a rogue ONU on 0/1/0.
        Db::exec('UPDATE olts SET inventory=? WHERE id=?', [json_encode(['pon' => ['0/1/0' => ['rogue' => 'Exist'], '0/1/1' => ['rogue' => 'Inexistent']]]), $olt['id']]);
        $saved = Olts::find((int) $olt['id']);
        $this->assertSame(['0/1/0' => 'Exist'], OltInventory::rogueValues(OltInventory::saved($saved)));
        $this->assertSame('Rogue ONU on port 0/1/0 of OLT olt-1', AdminAlerts::problems(['olt'])['rogue:' . $olt['id'] . ':0/1/0'] ?? null);

        $this->actingAsOperator('view');
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p
            ->where('attention.0.title', 'Rogue ONU on port 0/1/0 of olt-1')->where('attention.0.tone', 'danger')
            ->where('attention.0.action.href', '/olts/' . $olt['id'] . '?tab=ports'));

        // The OLT now reports "Inexistent" again: the changelog says it cleared, and the alert and the item go away.
        OltInventory::read($saved);
        $this->assertSame([['rogue_gone', '0/1/0', 'olt']], array_map(static fn (array $r) => [$r['action'], $r['position'], $r['source']],
            Db::select('SELECT action, position, source FROM olt_changes WHERE olt_id=? AND action LIKE "rogue%"', [$olt['id']])));
        $this->assertSame([], array_filter(array_keys(AdminAlerts::problems(['olt'])), static fn (string $k) => str_starts_with($k, 'rogue:')));
        $this->assertSame([], $this->changes());
    }

    public function test_a_configuration_copy_is_kept_sealed_and_only_when_it_changed(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->assertTrue(OltInventory::backup($olt));
        $this->assertFalse(OltInventory::backup($olt));
        $copy = Db::row('SELECT * FROM olt_config_backups');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM olt_config_backups'));
        $this->assertStringStartsWith('enc:v1:', $copy['body']);
        $this->assertStringNotContainsString('secret-in-the-config', $copy['body']);
        $text = OltInventory::backupText((int) $olt['id'], (int) $copy['id']);
        $this->assertStringStartsWith('[Active: H905MPLB', $text);
        $this->assertStringEndsWith("return\n", $text);
        $this->assertStringNotContainsString('MA5800-X7#', $text);
        $this->assertSame(strlen($text), (int) $copy['bytes']);
        $this->assertSame([], $this->changes());

        // The configuration changes on the OLT: the next copy is a new one.
        file_put_contents($this->log . '.sysname', 'renamed-olt');
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/backup")->assertSessionHas('flash.message', 'A copy of the configuration was saved.');
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM olt_config_backups'));
        $newest = (int) Db::value('SELECT MAX(id) FROM olt_config_backups');
        $this->get("/olts/{$olt['id']}/backups/$newest")->assertOk()->assertSee('sysname renamed-olt', false)->assertHeader('Content-Disposition');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="backup_download"'));
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->has('backups', 2)->where('changelog', fn ($rows) => !collect($rows)->contains('action', 'backup_download')));
        // Another OLT's copy cannot be fetched through this one.
        $this->get('/olts/' . ($olt['id'] + 1) . "/backups/$newest")->assertNotFound();

        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/backups/$newest")->assertForbidden();
        $this->post("/olts/{$olt['id']}/backup")->assertForbidden();
    }

    public function test_the_poller_reads_the_inventory_and_takes_the_daily_copy(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        OltSync::run($olt);
        $saved = Olts::find((int) $olt['id']);
        $this->assertNull($saved['last_inventory_error']);
        $this->assertNull($saved['last_backup_error']);
        $this->assertSame('MA5800-X7', OltInventory::saved($saved)['version']['product']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM olt_config_backups'));
        // Asked again at once: the inventory is read again, but a day has not passed, so no second copy is tried.
        file_put_contents($this->log, '');
        Db::exec('UPDATE olts SET sync_requested_at=NOW()');
        OltSync::run(Olts::find((int) $olt['id']));
        $this->assertContains('display version', $this->commands());
        $this->assertNotContains('display current-configuration', $this->commands());
    }

    public function test_pon_port_actions_send_one_command_and_believe_the_olt(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->ports($olt);
        OltInventory::read($olt);
        file_put_contents($this->log, '');
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/ports/" . self::PORT_A;
        $this->post("$base/autofind-off")->assertSessionHas('flash.type', 'success');
        $this->assertFalse(OltInventory::saved(Olts::find((int) $olt['id']))['pon']['0/1/0']['autofind']);
        $this->post("$base/autofind-on")->assertSessionHas('flash.type', 'success');
        $this->post("$base/restart-onus")->assertSessionHas('flash.type', 'success');
        $this->post("$base/off")->assertSessionHas('flash.type', 'success');
        $this->assertFalse(OltInventory::saved(Olts::find((int) $olt['id']))['pon']['0/1/0']['admin']);
        $this->post("$base/on")->assertSessionHas('flash.type', 'success');
        $this->assertSame(['port 0 ont-auto-find disable', 'port 0 ont-auto-find enable', 'ont reset 0 all', 'shutdown 0', 'undo shutdown 0'], $this->changes());
        $this->assertSame(5, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action LIKE "port\_%"'));
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->where('changelog.0.action', 'port_on')->where('changelog.0.position', '0/1/0')->where('changelog.0.commands', ['undo shutdown 0']));
        // An uplink is not a PON port, and nothing else may be asked for.
        $this->post("/olts/{$olt['id']}/ports/7/autofind-off")->assertNotFound();
        $this->post("$base/delete")->assertNotFound();
        // NOC may manage, but turning a whole port off is for people who may delete.
        $this->actingAsOperator('noc');
        $this->post("$base/off")->assertForbidden();
        $this->actingAsOperator('view');
        $this->post("$base/autofind-off")->assertForbidden();
        $this->assertCount(5, $this->changes());
    }

    public function test_port_and_card_actions_need_changes_allowed(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->ports($olt);
        $this->insertRow('olt_boards', ['olt_id' => $olt['id'], 'frame' => 0, 'slot' => 8, 'name' => 'H905MPLB', 'seen_at' => date('Y-m-d H:i:s')]);
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/ports/" . self::PORT_A . '/restart-onus')->assertSessionHas('flash.type', 'danger');
        $this->post("/olts/{$olt['id']}/cards/0/8/restart")->assertSessionHas('flash.type', 'danger');
        $this->assertSame([], $this->commands());
    }

    public function test_restarting_a_card_checks_which_card_is_in_the_slot(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->insertRow('olt_boards', ['olt_id' => $olt['id'], 'frame' => 0, 'slot' => 8, 'name' => 'H905MPLB', 'seen_at' => date('Y-m-d H:i:s')]);
        $this->insertRow('olt_boards', ['olt_id' => $olt['id'], 'frame' => 0, 'slot' => 2, 'name' => 'H901GPHF', 'seen_at' => date('Y-m-d H:i:s')]);
        $this->actingAsOperator('noc');
        $this->post("/olts/{$olt['id']}/cards/0/8/restart")->assertForbidden();
        $this->actingAsOperator();
        // The portal's list says GPHF is in slot 2, but the OLT says another card is there.
        $this->post("/olts/{$olt['id']}/cards/0/2/restart")->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Nothing was restarted'));
        $this->assertSame([], $this->changes());
        $this->post("/olts/{$olt['id']}/cards/0/8/restart")->assertSessionHas('flash.type', 'success');
        $this->assertSame(['board reset 0/8'], $this->changes());
        $this->post("/olts/{$olt['id']}/cards/0/5/restart")->assertNotFound();
    }

    public function test_the_command_line_runs_display_commands_only(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->actingAsOperator();
        $this->get("/olts/{$olt['id']}/cli?command=" . urlencode('display  version'))->assertOk()->assertJsonPath('ok', true)->assertJsonPath('output', fn (string $o) => str_contains($o, 'PRODUCT : MA5800-X7'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="cli"'));
        file_put_contents($this->log, '');
        foreach (['ont delete 0 3', 'config', 'display version; ont delete 0 3', 'display version | include x', 'display ont info 0 1 0 3 ?', 'undo service-port 2100'] as $bad) {
            $this->get("/olts/{$olt['id']}/cli?command=" . urlencode($bad))->assertOk()->assertJsonPath('ok', false);
        }
        $this->assertSame([], array_values(array_filter($this->commands(), static fn (string $c) => !in_array($c, ['enable', 'scroll', 'undo smart', 'quit', 'y'], true))));
        // A line break is folded into a space, so a second line can only ever be more words of the one display command.
        $this->get("/olts/{$olt['id']}/cli?command=" . urlencode("display version\nont delete 0 3"))->assertOk();
        $this->assertContains('display version ont delete 0 3', $this->commands());
        $this->assertSame([], $this->changes());
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/cli?command=display+version")->assertForbidden();
    }

    public function test_each_ports_traffic_is_kept_for_its_graph(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $port = ['if_index' => 7, 'name' => 'ethernet0/9/0', 'kind' => 'uplink', 'alias' => '', 'admin_up' => 1, 'oper_up' => 1, 'speed_mbps' => 1000, 'in_errors' => 0, 'out_errors' => 0];
        OltMonitor::saveHardware($olt, ['boards' => [], 'ports' => [$port + ['in_octets' => 1000, 'out_octets' => 1000]], 'fans' => []]);
        // No rate from a single read.
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM olt_port_samples'));
        Db::exec('UPDATE olt_ports SET sampled_at=sampled_at-60');
        OltMonitor::saveHardware($olt, ['boards' => [], 'ports' => [$port + ['in_octets' => 1000 + 750000, 'out_octets' => 1000 + 75000]], 'fans' => []]);
        $sample = Db::row('SELECT * FROM olt_port_samples');
        $this->assertEqualsWithDelta(100000, (float) $sample['in_bps'], 4000);
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/graph?chart=port&port=7&range=6h")->assertOk()->assertJsonStructure(['chart' => ['bucket']]);
        $this->get("/olts/{$olt['id']}/graph?chart=port&port=999")->assertNotFound();
    }

    public function test_time_servers_and_vlans_are_changed_with_care(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->ports($olt);
        OltInventory::read($olt);
        file_put_contents($this->log, '');
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/settings";
        $this->post("$base/ntp", ['action' => 'add', 'server' => '198.51.100.7'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/ntp", ['action' => 'remove', 'server' => '192.0.2.123'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/ntp", ['action' => 'add', 'server' => 'pool.ntp.org; undo vlan 100'])->assertSessionHas('flash.type', 'danger');
        $this->assertSame(['198.51.100.7'], array_column(OltInventory::saved(Olts::find((int) $olt['id']))['ntp'], 'server'));

        $this->post("$base/vlans", ['action' => 'add', 'vlan' => '300'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/uplink-vlans", ['action' => 'add', 'port' => 7, 'vlan' => 300])->assertSessionHas('flash.type', 'success');
        $this->assertSame([1, 100, 200, 300], OltInventory::saved(Olts::find((int) $olt['id']))['uplinks']['0/9/0']['vlans']);
        // Still on an uplink: not deleted.
        $this->post("$base/vlans", ['action' => 'remove', 'vlan' => 300])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'uplink'));
        $this->post("$base/uplink-vlans", ['action' => 'remove', 'port' => 7, 'vlan' => 300])->assertSessionHas('flash.type', 'success');
        $this->post("$base/vlans", ['action' => 'remove', 'vlan' => 300])->assertSessionHas('flash.type', 'success');
        $this->assertSame(['ntp-service unicast-server 198.51.100.7', 'undo ntp-service unicast-server 192.0.2.123', 'vlan 300 smart', 'port vlan 300 0/9 0', 'undo port vlan 300 0/9 0', 'undo vlan 300'], $this->changes());

        // What must never be sent: a VLAN ONUs use, the VLAN the OLT has its own address on, VLAN 1, a PON port, an unknown VLAN.
        $this->post("$base/vlans", ['action' => 'remove', 'vlan' => 100])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'used by 16 ONUs'));
        $this->post("$base/uplink-vlans", ['action' => 'remove', 'port' => 7, 'vlan' => 100])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'its own address'));
        $this->post("$base/vlans", ['action' => 'remove', 'vlan' => 1])->assertSessionHas('flash.type', 'danger');
        $this->post("$base/vlans", ['action' => 'add', 'vlan' => 100])->assertSessionHas('flash.type', 'danger');
        $this->post("$base/uplink-vlans", ['action' => 'add', 'port' => self::PORT_A, 'vlan' => 100])->assertSessionHas('flash.type', 'danger');
        $this->post("$base/uplink-vlans", ['action' => 'add', 'port' => 7, 'vlan' => 999])->assertSessionHas('flash.type', 'danger');
        $this->assertCount(6, $this->changes());

        // NOC may add, but removing is for people who may delete.
        $this->actingAsOperator('noc');
        $this->post("$base/ntp", ['action' => 'remove', 'server' => '198.51.100.7'])->assertForbidden();
        $this->actingAsOperator('view');
        $this->post("$base/vlans", ['action' => 'add', 'vlan' => 301])->assertForbidden();
        $this->assertCount(6, $this->changes());
    }

    public function test_a_vlan_is_created_with_its_name_description_and_uplinks_in_one_go(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->ports($olt);
        OltInventory::read($olt);
        $this->actingAsOperator('view');
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p
            ->where('details.vlans.1.id', 100)->where('details.vlans.1.name', 'internet')->where('details.vlans.1.desc', 'Customers')->where('details.vlans.1.own', true)
            ->where('details.vlans.0.own', false));
        file_put_contents($this->log, '');
        $this->actingAsOperator('noc');
        $base = "/olts/{$olt['id']}/settings";
        $this->post("$base/vlans", ['action' => 'add', 'vlan' => '300', 'type' => 'smart', 'name' => 'office', 'desc' => 'Main office', 'uplinks' => ['7']])->assertSessionHas('flash.message', 'VLAN 300 was created and is carried on 0/9/0.');
        $this->assertSame(['vlan 300 smart', 'vlan name 300 office', 'vlan desc 300 description "Main office"', 'port vlan 300 0/9 0'], $this->changes());
        $saved = OltInventory::saved(Olts::find((int) $olt['id']));
        $this->assertSame(['type' => 'smart', 'name' => 'office', 'desc' => 'Main office'], $saved['vlanInfo'][300]);
        $this->assertContains(300, $saved['uplinks']['0/9/0']['vlans']);
        $this->assertContains(1, $saved['uplinks']['0/9/0']['vlans']);

        // A range, without a name.
        $this->post("$base/vlans", ['action' => 'add', 'vlan' => '400', 'to' => '403', 'type' => 'standard', 'uplinks' => ['7']])->assertSessionHas('flash.type', 'success');
        $this->assertSame(['vlan 400 to 403 standard', 'port vlan 400 to 403 0/9 0'], array_slice($this->changes(), 4));
        $this->assertSame([300, 400, 401, 402, 403], array_values(array_filter(array_column(OltInventory::saved(Olts::find((int) $olt['id']))['vlans'], 'id'), static fn ($v) => $v >= 300)));

        // Refused before the OLT is asked: taken, out of range, a name with a space, too many, a port that is not an uplink.
        foreach ([['vlan' => '300'], ['vlan' => '402'], ['vlan' => '1'], ['vlan' => '5000'], ['vlan' => '500', 'name' => 'two words'], ['vlan' => '500', 'to' => '600'],
            ['vlan' => '500', 'to' => '502', 'name' => 'x'], ['vlan' => '500', 'uplinks' => [(string) self::PORT_A]], ['vlan' => '500', 'type' => 'super']] as $bad) {
            $this->post("$base/vlans", ['action' => 'add'] + $bad)->assertSessionHas('flash.type', 'danger');
        }
        $this->assertCount(6, $this->changes());
    }

    public function test_a_vlan_that_cannot_be_finished_is_taken_off_again(): void
    {
        $this->startAgent(['fail' => ['port vlan 300']]);
        $olt = $this->olt();
        $this->ports($olt);
        $this->actingAsOperator();
        $this->post("/olts/{$olt['id']}/settings/vlans", ['action' => 'add', 'vlan' => '300', 'name' => 'office', 'uplinks' => ['7']])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Made to fail by the test') && str_contains($m, 'taken off the OLT again'));
        $this->assertSame(['vlan 300 smart', 'vlan name 300 office', 'port vlan 300 0/9 0', 'undo vlan 300'], $this->changes());
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="vlan_add"'));
    }

    public function test_editing_a_vlan_sends_only_what_changed(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->ports($olt);
        OltInventory::read($olt);
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/settings";
        $this->post("$base/vlans", ['action' => 'add', 'vlan' => '300'])->assertSessionHas('flash.type', 'success');
        file_put_contents($this->log, '');
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'office', 'desc' => 'Main office', 'uplinks' => ['7']])->assertSessionHas('flash.message', 'VLAN 300 was saved on the OLT.');
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'office', 'desc' => 'Main office', 'uplinks' => ['7']])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Nothing to change'));
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'office', 'desc' => '', 'uplinks' => []])->assertSessionHas('flash.type', 'success');
        $this->assertSame(['vlan name 300 office', 'vlan desc 300 description "Main office"', 'port vlan 300 0/9 0', 'undo vlan desc 300', 'undo port vlan 300 0/9 0'], $this->changes());
        $this->assertSame(['type' => 'smart', 'name' => 'office', 'desc' => ''], OltInventory::saved(Olts::find((int) $olt['id']))['vlanInfo'][300]);
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->where('changelog.0.action', 'vlan_edit')->where('changelog.0.detail.vlan', 300));

        // The OLT's own VLAN never comes off an uplink, and NOC may add a port but not take one off.
        $this->post("$base/vlan-edit", ['vlan' => 100, 'name' => 'internet', 'desc' => 'Customers', 'uplinks' => []])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'its own address'));
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'office', 'desc' => '', 'uplinks' => ['7']])->assertSessionHas('flash.type', 'success');
        $this->actingAsOperator('noc');
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'office', 'desc' => '', 'uplinks' => []])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'right to delete'));
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'two words', 'uplinks' => ['7']])->assertSessionHas('flash.type', 'danger');
        $this->assertSame(['port vlan 300 0/9 0'], array_slice($this->changes(), 5));
        $this->actingAsOperator('view');
        $this->post("$base/vlan-edit", ['vlan' => 300, 'name' => 'x', 'uplinks' => ['7']])->assertForbidden();
    }

    public function test_the_changelog_keeps_what_was_done_from_the_portal(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator('noc', ['username' => 'teknik']);
        $this->post("/olts/{$olt['id']}/onus/$onu/reboot")->assertSessionHas('flash.type', 'success');
        $this->post("/olts/{$olt['id']}/onus/$onu/describe", ['description' => 'Flux 503'])->assertSessionHas('flash.type', 'success');
        $this->post("/olts/{$olt['id']}/provision", ['sn' => 'HWTCAAAA0001', 'mode' => 'bridge', 'line_profile' => '6', 'service_profile' => '1', 'vlan' => '100', 'gem' => '1', 'traffic_up' => '8', 'traffic_down' => '9', 'description' => 'New one'])->assertSessionHas('flash.type', 'success');
        $rows = Db::select('SELECT actor,source,action,sn,position,detail FROM olt_changes ORDER BY id');
        $this->assertSame(['onu_reboot', 'onu_describe', 'onu_add'], array_column($rows, 'action'));
        $this->assertSame(['teknik', 'portal', 'HWTC1234ABCD', '0/1/0:3'], [$rows[0]['actor'], $rows[0]['source'], $rows[0]['sn'], $rows[0]['position']]);
        $this->assertSame(['before' => 'Kati 2 hyrja A', 'after' => 'Flux 503'], array_intersect_key(json_decode($rows[1]['detail'], true), array_flip(['before', 'after'])));

        $this->actingAsOperator('view');
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p
            ->where('changelog.0.action', 'onu_add')->where('changelog.0.sn', 'HWTCAAAA0001')->where('changelog.0.onuName', 'New one')
            ->where('changelog.2.action', 'onu_reboot')->where('changelog.2.commands', ['ont reset 0 3'])->where('changelog.2.onuId', $onu));
        $new = (int) Db::value('SELECT id FROM olt_onus WHERE sn="HWTCAAAA0001"');
        $this->get("/olts/{$olt['id']}/onus/$new")->assertInertia(fn ($p) => $p->has('history', 1)->where('history.0.action', 'onu_add')->where('addedAt', fn ($at) => $at !== null));
        $this->get("/olts/{$olt['id']}/onus/$onu")->assertInertia(fn ($p) => $p->has('history', 2)->where('addedAt', null));
    }

    public function test_the_changelog_finds_what_was_changed_on_the_olt_itself(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $onu = static fn (int $id, string $sn, string $desc, int $active = 1) => ['frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => $id, 'sn' => $sn, 'description' => $desc, 'onu_model' => 'EG8145V5', 'line_profile' => 'SmartOLT_G', 'service_profile' => 'HG8346M', 'status' => 'online', 'active' => $active];
        // The first read finds every ONU: that is not a change.
        OltSync::saveOnus($olt, [$onu(1, 'HWTC00000001', 'Ana'), $onu(2, 'HWTC00000002', 'Ben'), $onu(3, 'HWTC00000003', 'Cel')]);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM olt_changes'));
        // Done elsewhere: 1 renamed, 2 moved and turned off, 3 removed, 4 added. The reads are minutes apart, as the poller's are.
        Db::exec('UPDATE olt_onus SET seen_at=seen_at-INTERVAL 5 MINUTE');
        OltSync::saveOnus($olt, [$onu(1, 'HWTC00000001', 'Ana Hoxha'), $onu(7, 'HWTC00000002', 'Ben', 0), $onu(4, 'HWTC00000004', 'Dea')]);
        $rows = Db::select('SELECT actor,source,action,sn,position,detail FROM olt_changes ORDER BY id');
        $this->assertSame(['onu_renamed', 'onu_moved', 'onu_disabled', 'onu_found', 'onu_gone'], array_column($rows, 'action'));
        $this->assertSame(['olt'], array_values(array_unique(array_column($rows, 'source'))));
        $this->assertSame('', $rows[0]['actor']);
        $this->assertSame(['from' => '0/1/0:2'], json_decode($rows[1]['detail'], true));
        $this->assertSame(['HWTC00000004', '0/1/0:4'], [$rows[3]['sn'], $rows[3]['position']]);
        // Nothing changed: nothing written.
        Db::exec('UPDATE olt_onus SET seen_at=seen_at-INTERVAL 5 MINUTE');
        OltSync::saveOnus($olt, [$onu(1, 'HWTC00000001', 'Ana Hoxha'), $onu(7, 'HWTC00000002', 'Ben', 0), $onu(4, 'HWTC00000004', 'Dea')]);
        $this->assertSame([], array_column(array_slice(Db::select('SELECT action,detail FROM olt_changes ORDER BY id'), 5), 'detail', 'action'));
    }

    public function test_a_changed_configuration_is_in_the_changelog_without_its_secrets(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->assertTrue(OltInventory::backup($olt));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM olt_changes'));
        file_put_contents($this->log . '.sysname', 'renamed-olt');
        $this->assertTrue(OltInventory::backup($olt));
        $row = Db::row('SELECT * FROM olt_changes');
        $this->assertSame(['config_changed', 'olt'], [$row['action'], $row['source']]);
        $diff = json_decode($row['detail'], true);
        $this->assertSame([' sysname renamed-olt'], $diff['added']);
        $this->assertSame([' sysname MA5800-X7'], $diff['removed']);
        $this->assertStringNotContainsString('secret-in-the-config', $row['detail']);
        $this->assertSame(' snmp-agent community read cipher ***', OltChangelog::mask(' snmp-agent community read cipher "secret-in-the-config"'));
        $this->assertSame(' ont add 0 3 password-auth *** omci', OltChangelog::mask(' ont add 0 3 password-auth 1234 omci'));
    }

    public function test_only_terminal_commands_that_change_something_are_kept(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        foreach (['display ont info 0 1 0 3', 'config', 'interface gpon 0/1', 'quit', 'scroll 512', ''] as $read) {
            OltChangelog::terminal((int) $olt['id'], $read);
        }
        OltChangelog::terminal((int) $olt['id'], 'ont reset 0 3', ['config', 'interface gpon 0/1']);
        $this->assertSame(['olt_terminal'], array_column(Db::select('SELECT action FROM olt_changes'), 'action'));
        $this->assertSame('console', Db::value('SELECT source FROM olt_changes'));
    }

    public function test_the_history_already_in_the_audit_log_is_copied_once(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $customer = $this->insertRow('customer_profiles', ['username' => 'gtest', 'account_status' => 'active', 'enabled' => 1]);
        $this->onu($olt);
        $this->insertRow('audit_logs', ['actor_username' => 'ana', 'action' => 'onu_reboot', 'entity_type' => 'onu', 'entity_id' => 'HWTC1234ABCD', 'after_json' => json_encode(['olt' => 'olt-1', 'position' => '0/1/0:3', 'commands' => ['ont reset 0 3']]), 'created_at' => '2026-11-10 10:00:00']);
        $this->insertRow('audit_logs', ['actor_username' => 'ana', 'action' => 'vlan_add', 'entity_type' => 'olt', 'entity_id' => (string) $olt['id'], 'after_json' => json_encode(['vlan' => 300, 'commands' => ['vlan 300 smart']]), 'created_at' => '2026-11-10 10:01:00']);
        $this->insertRow('audit_logs', ['actor_username' => 'ana', 'action' => 'olt_terminal', 'entity_type' => 'olt', 'entity_id' => (string) $olt['id'], 'after_json' => json_encode(['commands' => ['display version']]), 'created_at' => '2026-11-10 10:02:00']);
        $this->insertRow('audit_logs', ['actor_username' => 'ana', 'action' => 'backup_download', 'entity_type' => 'olt', 'entity_id' => (string) $olt['id'], 'after_json' => json_encode(['backup' => 1]), 'created_at' => '2026-11-10 10:03:00']);
        $this->insertRow('audit_logs', ['actor_username' => 'ana', 'action' => 'link_onu', 'entity_type' => 'client', 'entity_id' => 'gtest', 'after_json' => json_encode(['sn' => 'HWTC1234ABCD', 'source' => 'manual']), 'created_at' => '2026-11-10 10:04:00']);
        $sql = (string) file_get_contents(base_path('database/sql/041_olt_changelog.sql'));
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', (string) preg_replace('/^--.*$/m', '', $sql)) ?: []));
        foreach ([1, 2] as $run) {
            foreach ($statements as $statement) {
                Db::pdo()->exec($statement);
            }
        }
        $rows = Db::select('SELECT actor,action,sn,position,detail FROM olt_changes ORDER BY created_at');
        $this->assertSame(['onu_reboot', 'vlan_add', 'link_onu'], array_column($rows, 'action'));
        $this->assertSame(['HWTC1234ABCD', '0/1/0:3'], [$rows[0]['sn'], $rows[0]['position']]);
        $this->assertSame('gtest', json_decode($rows[2]['detail'], true)['client']);
        $this->assertGreaterThan(0, $customer);
    }

    public function test_the_onu_list_shows_one_port_or_the_weak_signals(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $a = $this->onu($olt);
        $b = $this->insertRow('olt_onus', ['olt_id' => $olt['id'], 'frame' => 0, 'slot' => 1, 'port' => 15, 'ont_id' => 0, 'sn' => 'HWTC0000000F', 'description' => 'Other port', 'status' => 'online', 'active' => 1, 'rx_dbm' => -29.5, 'seen_at' => date('Y-m-d H:i:s')]);
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}?port=0/1/15")->assertInertia(fn ($p) => $p->where('filters.port', '0/1/15')->where('result.total', 1)->where('result.rows.0.id', $b));
        $this->get("/olts/{$olt['id']}?status=weak")->assertInertia(fn ($p) => $p->where('result.total', 1)->where('result.rows.0.sn', 'HWTC0000000F'));
        $this->get("/olts/{$olt['id']}?port=0/1/0&status=online")->assertInertia(fn ($p) => $p->where('result.total', 1)->where('result.rows.0.id', $a));
        // Anything else is no filter at all, not an error.
        $this->get("/olts/{$olt['id']}?port=0/1/15;drop")->assertInertia(fn ($p) => $p->where('filters.port', '')->where('result.total', 2));
    }

    public function test_each_olt_has_its_own_signal_levels(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $onu = $this->onu($olt);
        Db::exec('UPDATE olt_onus SET rx_dbm=-28.5 WHERE id=?', [$onu]);
        $this->actingAsOperator();
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->where('counts.weak', 1)->where('olt.rxWarning', -27)->where('olt.rxCritical', -30));
        $input = ['name' => 'olt-1', 'model' => 'huawei_ma5800_x7', 'host' => '127.0.0.1', 'enabled' => true, 'snmp_version' => '2c', 'snmp_port' => (string) $this->snmpPort, 'telnet_enabled' => true, 'telnet_port' => (string) $this->telnetPort];
        $this->post("/olts/{$olt['id']}/save", $input + ['rx_warning' => '-29', 'rx_critical' => '-32'])->assertSessionHas('flash.type', 'success');
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->where('counts.weak', 0)->where('olt.rxWarning', -29)->where('olt.rxCritical', -32));
        $this->post("/olts/{$olt['id']}/save", $input + ['rx_warning' => '-32', 'rx_critical' => '-29'])->assertSessionHas('flash.type', 'danger');
        $this->post("/olts/{$olt['id']}/save", $input + ['rx_warning' => '5', 'rx_critical' => '-30'])->assertSessionHas('flash.type', 'danger');
        $this->assertSame([], $this->commands());
    }

    public function test_the_onu_page_reads_its_config_service_ports_and_lan_ports(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $onu = $this->onu($olt);
        $customer = $this->insertRow('customer_profiles', ['username' => 'gtest', 'first_name' => 'Isa', 'last_name' => 'A', 'address' => 'Flux 503', 'cell_phone' => '0038344000000', 'account_status' => 'active', 'enabled' => 1]);
        $this->insertRow('customer_onus', ['customer_id' => $customer, 'olt_id' => $olt['id'], 'sn' => 'HWTC1234ABCD', 'source' => 'manual', 'linked_at' => date('Y-m-d H:i:s')]);
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/onus/$onu")->assertOk()->assertInertia(fn ($p) => $p
            ->where('clients.0.address', 'Flux 503')->where('clients.0.phone', '0038344000000')
            ->where('disconnections', ['day' => 0, 'week' => 0])->where('olt.rxWarning', -27)->has('ranges'));
        $this->get("/olts/{$olt['id']}/onus/$onu/live")->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('data.servicePorts.0.index', 2100)->assertJsonPath('data.servicePorts.0.down', 9)->assertJsonPath('data.servicePorts.0.up', 8)
            ->assertJsonPath('data.lan.0.on', true)->assertJsonPath('data.lan.1.up', true)->assertJsonPath('data.lan.1.native', 1);
        $this->get("/olts/{$olt['id']}/onus/$onu/config")->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('output', fn (string $o) => str_starts_with($o, '[MA5800') && str_contains($o, 'ont add 0 3 sn-auth "485754431234ABCD"') && !str_contains($o, 'MA5800-X7#'));
        $this->assertSame([], $this->changes());
        // The list says another serial number is there: its config is not shown as this ONU's.
        $stale = $this->onu($olt, 'HWTC99999999', 12);
        $this->get("/olts/{$olt['id']}/onus/$stale/config")->assertJsonPath('ok', false);
    }

    public function test_speed_lan_ports_and_factory_settings_on_an_onu(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/onus/$onu";
        $this->post("$base/service-ports/2100", ['up' => 6, 'down' => 6])->assertSessionHas('flash.type', 'success');
        $this->get("$base/live")->assertJsonPath('data.servicePorts.0.down', 6)->assertJsonPath('data.servicePorts.0.up', 6);
        // Another ONU's service port, and a traffic table the OLT does not have.
        $this->post("$base/service-ports/2101", ['up' => 8, 'down' => 9])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'does not belong'));
        $this->post("$base/service-ports/2100", ['up' => 77, 'down' => 9])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'no such traffic table'));
        $this->post("$base/lan/4", ['state' => 'off'])->assertSessionHas('flash.type', 'success');
        $this->get("$base/live")->assertJsonPath('data.lan.1.on', false);
        $this->post("$base/lan/4", ['state' => 'on'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/lan/9", ['state' => 'off'])->assertSessionHas('flash.type', 'danger');
        $this->post("$base/factory-reset")->assertSessionHas('flash.type', 'success');
        $this->assertSame([
            'service-port 2100 inbound traffic-table index 6 outbound traffic-table index 6',
            'ont port attribute 0 3 eth 4 operational-state off', 'ont port attribute 0 3 eth 4 operational-state on',
            'ont factory-setting-restore 0 3',
        ], $this->changes());
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action IN ("onu_speed","onu_lan_off","onu_lan_on","onu_factory_reset")'));
        $this->actingAsOperator('noc');
        $this->post("$base/factory-reset")->assertForbidden();
        $this->actingAsOperator('view');
        $this->post("$base/service-ports/2100", ['up' => 8, 'down' => 9])->assertForbidden();
        $this->post("$base/lan/4", ['state' => 'off'])->assertForbidden();
        $this->assertCount(4, $this->changes());
    }

    public function test_each_onu_keeps_a_history_of_signal_state_and_traffic(): void
    {
        $b = '1.3.6.1.4.1.2011.5.14.5';
        // Two service ports of the ONU at 0/1/0:3 (rows 5 and 6), and one of another ONU.
        $oids = [];
        foreach ([[5, 3, 1000, 5000], [6, 3, 500, 2500], [9, 4, 7, 7]] as [$row, $ont, $up, $down]) {
            $oids += ["$b.2.1.2.$row" => [2, 0], "$b.2.1.3.$row" => [2, 1], "$b.2.1.4.$row" => [2, 0], "$b.2.1.5.$row" => [2, $ont], "$b.3.1.7.$row" => [70, $up], "$b.3.1.8.$row" => [70, $down]];
        }
        $this->startAgent([], $oids);
        $olt = $this->olt(false);
        $this->assertSame(['up' => 1500.0, 'down' => 7500.0], HuaweiMa5800::readTraffic(Olts::snmp($olt, 60.0))['0/1/0/3']);

        $onu = ['frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 3, 'sn' => 'HWTC1234ABCD', 'status' => 'online', 'rx_dbm' => -21.5, 'olt_rx_dbm' => -24.0];
        $id = $this->onu($olt);
        OltMonitor::recordOnus((int) $olt['id'], [$onu], ['0/1/0/3' => ['up' => 1500.0, 'down' => 7500.0]]);
        // The first read has nothing to compare with.
        $this->assertNull(Db::value('SELECT down_bps FROM olt_onu_samples'));
        Db::exec('UPDATE olt_onus SET counters_at=counters_at-300');
        Db::exec('UPDATE olt_onu_samples SET sampled_at=sampled_at-300');
        OltMonitor::recordOnus((int) $olt['id'], [['status' => 'offline', 'rx_dbm' => null, 'olt_rx_dbm' => null] + $onu], ['0/1/0/3' => ['up' => 1500.0 + 37500, 'down' => 7500.0 + 3750000]]);
        $latest = Db::row('SELECT * FROM olt_onu_samples ORDER BY sampled_at DESC LIMIT 1');
        $this->assertEqualsWithDelta(100000, (float) $latest['down_bps'], 3000);
        $this->assertEqualsWithDelta(1000, (float) $latest['up_bps'], 30);
        $this->assertSame(['day' => 1, 'week' => 1], OltMonitor::disconnections((int) $olt['id'], 'HWTC1234ABCD'));

        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/onus/$id/graph?chart=traffic&range=6h")->assertOk()->assertJsonStructure(['chart' => ['bucket']])->assertJsonPath('chart.unit', 'bps');
        $this->get("/olts/{$olt['id']}/onus/$id/graph?chart=signal&range=6h")->assertOk()->assertJsonPath('chart.unit', 'dbm');
        $this->get("/olts/{$olt['id']}/onus/$id")->assertInertia(fn ($p) => $p->where('disconnections.day', 1));
        // The poller fills the history by itself.
        Db::exec('DELETE FROM olt_onu_samples');
        OltSync::run($olt);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM olt_onu_samples'));
    }

    public function test_the_onus_internet_connection_is_set_through_omci(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $customer = $this->insertRow('customer_profiles', ['username' => 'isa.a', 'first_name' => 'Isa', 'last_name' => 'A', 'account_status' => 'active', 'enabled' => 1]);
        $this->insertRow('customer_onus', ['customer_id' => $customer, 'olt_id' => $olt['id'], 'sn' => 'HWTC1234ABCD', 'source' => 'manual', 'linked_at' => date('Y-m-d H:i:s')]);
        $this->insertRow('radcheck', ['username' => 'isa.a', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'S3cret-pw']);
        $other = $this->insertRow('customer_profiles', ['username' => 'someone.else', 'first_name' => 'X', 'last_name' => 'Y', 'account_status' => 'active', 'enabled' => 1]);
        $this->insertRow('radcheck', ['username' => 'someone.else', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'other-pw']);
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/onus/$onu";

        $this->get($base)->assertInertia(fn ($p) => $p->where('clients.0.pppoe', true)->missing('clients.0.password'));
        $this->get("$base/omci-options")->assertJsonPath('ok', true)->assertJsonPath('data.vlans', [1, 100])->assertJsonPath('data.wanProfiles.0.id', 0);

        $this->post("$base/wan", ['mode' => 'dhcp', 'vlan' => 100, 'wan_profile' => 0, 'route_profile' => 1, 'route_lan' => '1'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/wan", ['mode' => 'pppoe', 'vlan' => 100, 'pppoe_client' => 'isa.a'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/wan", ['mode' => 'pppoe', 'vlan' => 100, 'priority' => 3, 'ip_index' => 0, 'pppoe_user' => 'typed', 'pppoe_password' => 'typed-pw'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/wan", ['mode' => 'static', 'vlan' => 100, 'ip' => '10.20.0.2', 'mask' => '255.255.255.0', 'gateway' => '10.20.0.1', 'dns1' => '1.1.1.1'])->assertSessionHas('flash.type', 'success');
        $this->assertSame([
            'ont ipconfig 0 3 ip-index 1 dhcp vlan 100 priority 0', 'ont internet-config 0 3 ip-index 1', 'ont wan-config 0 3 ip-index 1 profile-id 0', 'ont policy-route-config 0 3 profile-id 1',
            'ont port route 0 3 eth 1 enable', 'ont port route 0 3 eth 2 enable', 'ont port route 0 3 eth 3 enable', 'ont port route 0 3 eth 4 enable',
            'ont ipconfig 0 3 ip-index 1 pppoe vlan 100 priority 0 user-account username isa.a password S3cret-pw', 'ont internet-config 0 3 ip-index 1',
            'ont ipconfig 0 3 ip-index 0 pppoe vlan 100 priority 3 user-account username typed password typed-pw', 'ont internet-config 0 3 ip-index 0',
            'ont ipconfig 0 3 ip-index 1 static ip-address 10.20.0.2 mask 255.255.255.0 gateway 10.20.0.1 pri-dns 1.1.1.1 vlan 100 priority 0', 'ont internet-config 0 3 ip-index 1',
        ], $this->changes());
        // The password went to the OLT, but is kept nowhere.
        $kept = (string) Db::value('SELECT GROUP_CONCAT(after_json) FROM audit_logs WHERE action="onu_wan"');
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="onu_wan"'));
        $this->assertStringNotContainsString('S3cret-pw', $kept);
        $this->assertStringNotContainsString('typed-pw', $kept);
        $this->assertStringContainsString('password ******', $kept);

        // Refused before anything is sent.
        $refused = [
            ['mode' => 'pppoe', 'vlan' => 100, 'pppoe_client' => 'someone.else'],
            ['mode' => 'pppoe', 'vlan' => 100, 'pppoe_user' => 'a b', 'pppoe_password' => 'x'],
            ['mode' => 'pppoe', 'vlan' => 100, 'pppoe_user' => 'a', 'pppoe_password' => "x\nont delete 0 3"],
            ['mode' => 'pppoe', 'vlan' => 100, 'pppoe_user' => 'a', 'pppoe_password' => 'x?'],
            ['mode' => 'static', 'vlan' => 100, 'ip' => '10.20.0.2', 'mask' => '255.0.255.0', 'gateway' => '10.20.0.1', 'dns1' => '1.1.1.1'],
            ['mode' => 'static', 'vlan' => 100, 'ip' => '10.20.0.2', 'mask' => '255.255.255.0', 'gateway' => '10.30.0.1', 'dns1' => '1.1.1.1'],
            ['mode' => 'static', 'vlan' => 100, 'ip' => '10.20.0.2; ont delete', 'mask' => '255.255.255.0', 'gateway' => '10.20.0.1', 'dns1' => '1.1.1.1'],
            ['mode' => 'dhcp', 'vlan' => 333],
            ['mode' => 'dhcp', 'vlan' => 100, 'wan_profile' => 9],
            ['mode' => 'dhcp', 'vlan' => 100, 'priority' => 8],
            ['mode' => 'bridge', 'vlan' => 100],
        ];
        foreach ($refused as $input) {
            $this->post("$base/wan", $input)->assertSessionHas('flash.type', 'danger');
        }
        $this->assertCount(14, $this->changes());

        // A LAN port's native VLAN, read back from the OLT.
        $this->post("$base/lan-vlan/4", ['vlan' => 100, 'priority' => 0])->assertSessionHas('flash.type', 'success');
        $this->get("$base/live")->assertJsonPath('data.lan.1.native', 100);
        $this->post("$base/lan-vlan/4", ['vlan' => 333])->assertSessionHas('flash.type', 'danger');
        $this->post("$base/lan-vlan/9", ['vlan' => 100])->assertSessionHas('flash.type', 'danger');
        $this->assertSame('ont port native-vlan 0 3 eth 4 vlan 100 priority 0', $this->changes()[14]);
        $this->assertCount(15, $this->changes());

        $this->actingAsOperator('view');
        $this->post("$base/wan", ['mode' => 'dhcp', 'vlan' => 100])->assertForbidden();
        $this->post("$base/lan-vlan/4", ['vlan' => 100])->assertForbidden();
    }

    public function test_the_onus_omci_settings_are_read_from_its_running_config(): void
    {
        // As a real MA5800 printed them for a routing and a bridging ONU, with a line the OLT wrapped.
        $routing = "[MA5800V100R018: 8300]\n#\n[gpon]\n  <gpon-0/1>\n interface gpon 0/1\n ont add 15 15 sn-auth \"485754433D6EF49C\" omci ont-lineprofile-id 6 ont-srvprofile-id 2 desc \"flux kabin\"\n"
            . " ont ipconfig 15 15 ip-index 1 dhcp vlan 10 priority 0\n ont internet-config 15 15 ip-index 1\n ont wan-config 15 15 ip-index 1 profile-id 0\n ont policy-route-config 15 15 profile-id 1\n"
            . " ont port route 15 15 eth 1 enable\n ont port route 15 15 eth 2 enable\n ont port route 15 15 eth 3 enable\n ont port route 15 15 eth 4 enable\n ont port route 15 14 eth 1 enable\n#\nreturn\n";
        $state = HuaweiCli::onuConfig($routing, 15, 15);
        $this->assertSame('routing', $state['mode']);
        $this->assertSame([1 => ['mode' => 'dhcp', 'vlan' => 10, 'priority' => 0, 'user' => '', 'internet' => true, 'profile' => 0]], $state['wans']);
        $this->assertSame(1, $state['routePolicy']);
        $this->assertSame([1, 2, 3, 4], array_keys($state['ports']));
        $this->assertTrue($state['ports'][4]['route']);

        $bridging = " interface gpon 0/1\n ont add 15 7 sn-auth \"485754431A829575\" omci ont-lineprofile-id 6 ont-srvprofile-id 1 desc \"ILIR\"\n ont port native-vlan 15 7 eth 1 vlan 10 priority 0\n ont port vlan 15 7 eth 2 translation 20 user-vlan 20\n ont port vlan 15 7 eth 3\ntransparent\n";
        $state = HuaweiCli::onuConfig($bridging, 15, 7);
        $this->assertSame('bridging', $state['mode']);
        $this->assertSame([], $state['wans']);
        $this->assertSame(['route' => false, 'native' => 10, 'tagged' => [], 'transparent' => false], $state['ports'][1]);
        $this->assertSame([20], $state['ports'][2]['tagged']);
        $this->assertTrue($state['ports'][3]['transparent']);
        $pppoe = HuaweiCli::onuConfig(" ont ipconfig 0 3 ip-index 0 pppoe vlan 30 priority 2 user-account username isa.a password \"%^%#x%^%#\"\n", 0, 3);
        $this->assertSame(['mode' => 'pppoe', 'vlan' => 30, 'priority' => 2, 'user' => 'isa.a', 'internet' => false, 'profile' => null], $pppoe['wans'][0]);
    }

    public function test_switching_between_routing_and_bridging_and_the_ethernet_port_modes(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/onus/$onu";
        $this->get("$base/omci-options")->assertJsonPath('data.state.mode', 'routing')->assertJsonPath('data.state.wans.1.vlan', 100)->assertJsonPath('data.state.ports.4.route', true)
            ->assertJsonPath('data.trafficTables.1.id', 8);

        // To bridging: exactly what the running config shows is taken off, then each of the 4 LAN ports goes on the VLAN.
        $this->post("$base/bridge", ['vlan' => 100])->assertSessionHas('flash.type', 'success');
        $this->assertSame([
            'ont port route 0 3 eth 1 disable', 'ont port route 0 3 eth 4 disable', 'undo ont policy-route-config 0 3',
            'undo ont wan-config 0 3 ip-index 1', 'undo ont internet-config 0 3 ip-index 1', 'undo ont ipconfig 0 3 ip-index 1',
            'ont port native-vlan 0 3 eth 1 vlan 100 priority 0', 'ont port native-vlan 0 3 eth 2 vlan 100 priority 0',
            'ont port native-vlan 0 3 eth 3 vlan 100 priority 0', 'ont port native-vlan 0 3 eth 4 vlan 100 priority 0',
        ], $this->changes());
        $this->get("$base/omci-options")->assertJsonPath('data.state.mode', 'bridging')->assertJsonPath('data.state.wans', [])->assertJsonPath('data.state.ports.3.native', 100);
        $this->post("$base/bridge", ['vlan' => 333])->assertSessionHas('flash.type', 'danger');
        $this->assertCount(10, $this->changes());

        // Back to routing with the WAN form.
        $this->post("$base/wan", ['mode' => 'dhcp', 'vlan' => 100, 'route_lan' => '1'])->assertSessionHas('flash.type', 'success');
        $this->get("$base/omci-options")->assertJsonPath('data.state.mode', 'routing')->assertJsonPath('data.state.ports.2.route', true);
        $this->assertCount(16, $this->changes());

        // Port modes: only what differs is sent, what goes first.
        $this->post("$base/eth/4", ['mode' => 'trunk', 'tagged' => ['100'], 'native' => '1'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/eth/4", ['mode' => 'transparent'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/eth/4", ['mode' => 'access', 'native' => '100'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/eth/4", ['mode' => 'lan'])->assertSessionHas('flash.type', 'success');
        $this->post("$base/eth/4", ['mode' => 'lan'])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'already'));
        $this->assertSame([
            'ont port route 0 3 eth 4 disable', 'ont port native-vlan 0 3 eth 4 vlan 1 priority 0', 'ont port vlan 0 3 eth 4 translation 100 user-vlan 100',
            'undo ont port vlan 0 3 eth 4 translation 100 user-vlan 100', 'ont port vlan 0 3 eth 4 transparent',
            'undo ont port vlan 0 3 eth 4 transparent', 'ont port native-vlan 0 3 eth 4 vlan 100 priority 0',
            'ont port route 0 3 eth 4 enable',
        ], array_slice($this->changes(), 16));
        foreach ([['mode' => 'trunk'], ['mode' => 'access'], ['mode' => 'trunk', 'tagged' => ['333']], ['mode' => 'hybrid'], ['mode' => 'access', 'native' => '5000']] as $bad) {
            $this->post("$base/eth/4", $bad)->assertSessionHas('flash.type', 'danger');
        }
        $this->post("$base/eth/9", ['mode' => 'lan'])->assertSessionHas('flash.type', 'danger');
        $this->assertCount(24, $this->changes());
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="onu_bridge"'));
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="onu_eth_port"'));

        // A speed by its size: a new traffic table, refused if the OLT has that speed already.
        $this->post("$base/add-speed", ['mbps' => 100])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, '100M as traffic table 10'));
        $this->assertSame('traffic table ip index 10 cir 102400 pir 102400 priority 0 priority-policy local-setting', $this->changes()[24]);
        $this->post("$base/add-speed", ['mbps' => 100])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'already has 100M'));
        $this->post("$base/add-speed", ['mbps' => 0])->assertSessionHas('flash.type', 'danger');
        $this->assertCount(25, $this->changes());
        $this->get($base)->assertInertia(fn ($p) => $p->where('trafficTables', fn ($t) => in_array(10, array_column(is_array($t) ? $t : $t->toArray(), 'id'), true)));

        $this->actingAsOperator('view');
        foreach (['bridge' => ['vlan' => 100], 'add-speed' => ['mbps' => 50], 'eth/4' => ['mode' => 'lan']] as $path => $input) {
            $this->post("$base/$path", $input)->assertForbidden();
        }
    }

    public function test_the_terminal_runs_any_command_in_its_mode_and_asks_first_when_it_matters(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->onu($olt);
        $this->actingAsOperator();
        $term = fn (string $command, array $context = [], array $more = []) => $this->postJson("/olts/{$olt['id']}/terminal", ['command' => $command, 'context' => $context] + $more)->assertOk();

        $term('display board 0')->assertJsonPath('ok', true)->assertJsonPath('prompt', 'MA5800-X7#')->assertJsonPath('context', []);
        $term('config')->assertJsonPath('prompt', 'MA5800-X7(config)#')->assertJsonPath('context', ['config']);
        $term('interface gpon 0/1', ['config'])->assertJsonPath('prompt', 'MA5800-X7(config-if-gpon-0/1)#')->assertJsonPath('context', ['config', 'interface gpon 0/1']);
        $at = ['config', 'interface gpon 0/1'];
        $term('display ont info 0 3', $at)->assertJsonPath('output', fn (string $o) => str_contains($o, 'Kati 2 hyrja A'))->assertJsonPath('context', $at);
        $term('no such thing', $at)->assertJsonPath('ok', true)->assertJsonPath('output', fn (string $o) => str_contains($o, 'Unknown command'));

        // Careful: nothing is sent until the operator says yes; then the OLT's own question is answered no until they say yes again.
        $term('ont reset 0 3', $at)->assertJsonPath('careful', true);
        $this->assertSame([], array_values(array_filter($this->changes(), static fn (string $c) => str_starts_with($c, 'ont reset'))));
        $term('ont reset 0 3', $at, ['confirmed' => true])->assertJsonPath('careful', false)->assertJsonPath('asked', fn (string $q) => str_contains($q, '(y/n)'))
            ->assertJsonPath('output', fn (string $o) => !str_contains($o, 'success'));
        $term('ont reset 0 3', $at, ['confirmed' => true, 'yes' => true])->assertJsonPath('asked', null)->assertJsonPath('output', fn (string $o) => str_contains($o, 'success: 1'));

        foreach (['quit', 'display ont info 0 3 ?', "display board 0\nreboot", str_repeat('x', 600)] as $refused) {
            $term($refused)->assertJsonPath('ok', false);
        }
        $term('display board 0', ['save'])->assertJsonPath('ok', false);
        $term('display board 0', array_fill(0, 7, 'config'))->assertJsonPath('ok', false);

        // In the audit log, with secrets masked.
        $term('snmp-agent community read s3cret-ro', ['config']);
        $term('display ont info 0 3', $at);
        $logged = (string) Db::value('SELECT GROUP_CONCAT(after_json) FROM audit_logs WHERE action="olt_terminal"');
        $this->assertStringNotContainsString('s3cret-ro', $logged);
        $this->assertStringContainsString('community read ******', $logged);
        $this->assertSame('display ont info 0 3', json_decode((string) Db::value('SELECT after_json FROM audit_logs WHERE action="olt_terminal" ORDER BY id DESC LIMIT 1'), true)['commands'][0]);

        // Only operators who may delete, and only on an OLT that allows changes.
        $this->actingAsOperator('noc');
        $this->postJson("/olts/{$olt['id']}/terminal", ['command' => 'display board 0'])->assertForbidden();
        $this->actingAsOperator('view');
        $this->postJson("/olts/{$olt['id']}/terminal", ['command' => 'display board 0'])->assertForbidden();
        $this->actingAsOperator();
        Db::exec('UPDATE olts SET manage_enabled=0 WHERE id=?', [$olt['id']]);
        $this->postJson("/olts/{$olt['id']}/terminal", ['command' => 'display board 0'])->assertJsonPath('ok', false)->assertJsonPath('error', fn (string $e) => str_contains($e, 'Allow changes'));
    }

    public function test_one_read_of_the_olt_serves_the_onu_page_and_its_tabs(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $onu = $this->onu($olt);
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/onus/$onu";
        $logins = fn () => count(array_filter($this->commands(), static fn (string $c) => $c === 'scroll'));

        $this->getJson("$base/live")->assertJsonPath('ok', true)->assertJsonPath('data.omci.mode', 'routing')->assertJsonPath('data.config', fn (string $c) => str_contains($c, 'ont add 0 3'));
        $this->assertSame(1, $logins());
        // The reload, the Running config tab and the ONU's part of the Configure tab: no new login.
        $this->getJson("$base/live")->assertJsonPath('ok', true)->assertJsonPath('age', fn (int $age) => $age <= 30);
        $this->getJson("$base/config")->assertJsonPath('output', fn (string $o) => str_contains($o, 'ont add 0 3'));
        $this->assertSame(1, $logins());
        $this->getJson("$base/omci-options")->assertJsonPath('data.state.mode', 'routing');
        $this->assertSame(2, $logins(), 'only the OLT\'s VLANs and profiles, once');
        $this->getJson("$base/omci-options")->assertJsonPath('ok', true);
        $this->assertSame(2, $logins());
        // Get status, Live and Read again ask the OLT.
        $this->getJson("$base/live?fresh=1")->assertJsonPath('age', fn (int $age) => $age <= 1);
        $this->getJson("$base/config?fresh=1")->assertJsonPath('ok', true);
        $this->assertSame(4, $logins());
        // A change makes the next read new.
        $this->post("$base/eth/4", ['mode' => 'access', 'native' => '100'])->assertSessionHas('flash.type', 'success');
        $before = $logins();
        $this->getJson("$base/live")->assertJsonPath('data.omci.ports.4.native', 100);
        $this->assertSame($before + 1, $logins());
    }

    public function test_the_onu_page_shows_its_last_day_as_a_strip(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $id = $this->onu($olt);
        $now = time();
        $sample = fn (int $ago, int $online) => $this->insertRow('olt_onu_samples', ['olt_id' => $olt['id'], 'sn' => 'HWTC1234ABCD', 'sampled_at' => $now - $ago, 'online' => $online]);
        // Known before the window, online until 10 hours ago, then no reads for an hour, offline for 10 minutes, back online.
        $sample(86400 + 120, 1);
        for ($ago = 86400 - 180; $ago >= 36000; $ago -= 300) {
            $sample($ago, 1);
        }
        $sample(32400, 0);
        $sample(32100, 0);
        for ($ago = 31800; $ago >= 60; $ago -= 300) {
            $sample($ago, 1);
        }
        $timeline = OltMonitor::timeline((int) $olt['id'], 'HWTC1234ABCD');
        $states = array_column($timeline['segments'], 'state');
        $this->assertSame(['online', 'none', 'offline', 'online'], $states);
        $this->assertSame($timeline['from'], $timeline['segments'][0]['from']);
        $this->assertSame($timeline['to'], $timeline['segments'][3]['to']);
        // The stretches meet without gaps or overlaps.
        foreach (array_slice($timeline['segments'], 1) as $i => $segment) {
            $this->assertSame($timeline['segments'][$i]['to'], $segment['from']);
        }
        $this->assertSame(600, $timeline['segments'][2]['to'] - $timeline['segments'][2]['from']);
        // An ONU never read is all unknown, not offline.
        $this->assertSame([['from' => $timeline['from'], 'to' => $timeline['to'], 'state' => 'none']], OltMonitor::timeline((int) $olt['id'], 'HWTC00000000')['segments']);

        Db::exec('UPDATE olt_onus SET status="online",last_up_at=? WHERE id=?', [date('Y-m-d H:i:s', $now - 3600), $id]);
        $this->actingAsOperator('view');
        $this->get("/olts/{$olt['id']}/onus/$id")->assertOk()->assertInertia(fn ($p) => $p
            ->where('timeline.segments.2.state', 'offline')
            ->where('since', fn (int $s) => $s >= 3600 && $s < 3700));
    }

    public function test_fan_speed_power_and_uptime_come_over_snmp(): void
    {
        $tray = '1.3.6.1.4.1.2011.6.1.1';
        $this->startAgent([], [
            "$tray.9.1.2.0.0" => [2, 1950], "$tray.9.1.2.0.1" => [2, 0],
            "$tray.3.1.2.0.0" => [4, base64_encode('Fan block')], "$tray.3.1.4.0.0" => [2, 1],
            "$tray.3.1.2.0.3" => [4, base64_encode('Power fault')], "$tray.3.1.4.0.3" => [2, -1],
            '1.3.6.1.4.1.2011.2.6.7.1.1.1.1.11.0' => [2, 501],
            '1.3.6.1.4.1.2011.6.3.3.2.1.21.0.8' => [4, base64_encode('H905MPLB')], '1.3.6.1.4.1.2011.6.3.3.2.1.8.0.8' => [2, 2],
            '1.3.6.1.4.1.2011.2.6.7.1.1.2.1.11.0.8' => [2, 125],
            '1.3.6.1.2.1.1.3.0' => [67, 360000],
        ]);
        $olt = $this->olt(false);
        $read = HuaweiMa5800::readHardware(Olts::snmp($olt, 60.0));
        $this->assertSame([['Fan 0', 'Normal', 1, 1950], ['Fan 1', 'Stopped', 0, 0]], array_map(static fn (array $f) => [$f['label'], $f['state'], $f['ok'], $f['speed_rpm']], $read['fans']));
        $this->assertSame([501, 3600, 125], [$read['power_w'], $read['uptime_s'], $read['boards'][0]['power_w']]);
        OltMonitor::saveHardware($olt, $read);
        $saved = Olts::find((int) $olt['id']);
        $this->assertSame(501, (int) $saved['power_w']);
        $this->assertEqualsWithDelta(time() - 3600, strtotime((string) $saved['booted_at']), 5);
        $this->actingAsOperator();
        $this->get('/olts/' . $olt['id'])->assertInertia(fn ($p) => $p->where('details.power', 501)->where('hardware.fans.0.rpm', 1950)->where('hardware.boards.0.watts', 125));
    }

    public function test_the_mac_table_downloads_as_a_file(): void
    {
        $this->startAgent();
        $olt = $this->olt(false);
        $this->onu($olt);
        $this->insertRow('olt_macs', ['olt_id' => $olt['id'], 'mac' => '789a1806a44a', 'vlan' => 100, 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 3, 'seen_at' => date('Y-m-d H:i:s')]);
        $this->actingAsOperator('view');
        $csv = $this->get("/olts/{$olt['id']}/macs.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('78:9A:18:06:A4:4A,100,0/1/0:3,HWTC1234ABCD', $csv);
    }

    public function test_command_line_answers_are_read_as_the_olt_prints_them(): void
    {
        $this->assertSame('HWTCA1B2C3D4', HuaweiCli::serialFromCli('48575443A1B2C3D4 (HWTC-A1B2C3D4)'));
        $this->assertSame('48575443A1B2C3D4', HuaweiCli::serialForCli('hwtc-a1b2c3d4'));
        $this->assertSame('48575443A1B2C3D4', HuaweiCli::serialForCli('48575443A1B2C3D4'));
        $this->assertNull(HuaweiCli::serialForCli('HWTC25D2'));
        $this->assertSame('Failure: The ONT does not exist', HuaweiCli::failure("display ont info 15 99 \n  Failure: The ONT does not exist\n"));
        $this->assertSame("% Unknown command, the error locates at '^'", HuaweiCli::failure("display ont port state0/1150\n                           ^\n  % Unknown command, the error locates at '^'\n"));
        $this->assertNull(HuaweiCli::failure("  Number of ONTs that can be added: 1, success: 1\n  PortID :15, ONTID :16\n"));
        $ports = HuaweiCli::servicePorts("       4   10 common   gpon 0/1 /15 2    1     vlan  10         9    8    down \n      16   10 common   gpon 0/1 /15 14   1     vlan  10         9    8    up   \n   Total : 2  (Up/Down :   1/1)\n");
        $this->assertSame([[4, 15, 2, 9, 8, false], [16, 15, 14, 9, 8, true]], array_map(static fn (array $p) => [$p['index'], $p['port'], $p['ont_id'], $p['rx'], $p['tx'], $p['up']], $ports));
        $this->assertSame([['id' => 6, 'cir' => 'off', 'pir' => 'off'], ['id' => 7, 'cir' => '2048', 'pir' => '10432']], HuaweiCli::trafficTables("     6 off      off        off      off          0 -                  tag-pri\n     7 2048     67536      10432    335824       2 -                local-pri\n  Total Num : 2\n"));
        $this->assertSame([['id' => 1, 'name' => 'smartolt_eth1-4_wifi1_wan1', 'bound' => 11]], HuaweiCli::profiles("  Profile-ID  Profile-name                                Binding times\n  1           smartolt_eth1-4_wifi1_wan1                  11           \n  Total: 1\n"));
        $this->assertSame(4, HuaweiCli::ethPorts("  POTS          2               -\n  ETH           4               -\n"));
        $this->assertNull(HuaweiCli::ethPorts("  ETH           adaptive        4\n"));
    }

    public function test_what_the_olt_printed_is_shown_without_echo_prompt_and_rules(): void
    {
        $out = "display ont register-info 15 3 \r\n ------------------\r\n  Index               : 1\r\n  UpTime              : 2026-11-18 00:55:43+01:00\r\n ------------------\r\n Total :  1\r\n\r\nMA5800-X7(config-if-gpon-0/1)#";
        $this->assertSame("  Index               : 1\n  UpTime              : 2026-11-18 00:55:43+01:00\n Total :  1", HuaweiCli::printed($out));
        $this->assertSame("  Index               : 1\n  UpTime              : 2026-11-18 00:55:43+01:00", HuaweiCli::printed($out, true));
    }

    public function test_an_uplink_port_gets_a_description_on_the_olt(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->ports($olt);
        file_put_contents($this->log, '');
        $this->actingAsOperator();
        $base = "/olts/{$olt['id']}/settings/uplink-desc";
        $this->post($base, ['port' => 7, 'description' => "To core \"MT\"?\r\nundo vlan 100"])->assertSessionHas('flash.type', 'success');
        $this->assertSame('To core MT undo vlan 100', Db::value('SELECT alias FROM olt_ports WHERE olt_id=? AND if_index=7', [$olt['id']]));
        $this->post($base, ['port' => 7, 'description' => ''])->assertSessionHas('flash.type', 'success');
        $this->assertSame('', Db::value('SELECT alias FROM olt_ports WHERE olt_id=? AND if_index=7', [$olt['id']]));
        // Only uplink ports.
        $this->post($base, ['port' => self::PORT_A, 'description' => 'x'])->assertSessionHas('flash.type', 'danger');
        $this->assertSame(['port desc 0/9/0 description "To core MT undo vlan 100"', 'undo port desc 0/9/0'], $this->changes());
    }
}
