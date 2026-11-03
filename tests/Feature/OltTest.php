<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\Olt\HuaweiMa5800;
use App\Support\Olt\OltCli;
use App\Support\Olt\OltMapper;
use App\Support\Olt\Olts;
use App\Support\Olt\OltSync;
use App\Support\Secrets;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OltTest extends TestCase
{
    private const BASE = '1.3.6.1.4.1.2011.6.128.1.1.2';
    /** GPON 0/1/0 and 0/2/5 in the MA5800's numbering. */
    private const PORT_A = 4194312192;
    private const PORT_B = 4194321664;

    private const MAC_TABLE = "  ------------------------------------------------------------------------\n"
        . "  SRV-P BUNDLE TYPE MAC            MAC TYPE F /S /P  VPI  VCI   VLAN ID\n"
        . "  INDEX INDEX\n"
        . "  ------------------------------------------------------------------------\n"
        . "   2100    -   gpon 789a-1806-a44a dynamic  0 /1 /0  3    1     100\n"
        . "@MORE@   2101    -   gpon f41e-5799-2771 dynamic  0 /2 /5  12   1     100\n"
        . "      -    -   eth  0011-2233-4455 dynamic  0 /9 /0  -    -     100\n"
        . "  ------------------------------------------------------------------------\n"
        . "  Total: 3\n";

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

    /** @return array<string, array{int, mixed}> */
    private static function oids(): array
    {
        $b = self::BASE;
        $a = self::PORT_A . '.3';
        $c = self::PORT_B . '.12';
        $oid = [];
        $text = static fn (string $v) => [4, base64_encode($v)];
        $oid['1.3.6.1.2.1.1.1.0'] = $text('Huawei Integrated Access Software MA5800-X7');
        $oid['1.3.6.1.2.1.2.2.1.2.' . self::PORT_A] = $text('GPON 0/1/0');
        $oid['1.3.6.1.2.1.2.2.1.2.' . self::PORT_B] = $text('GPON 0/2/5');
        $oid['1.3.6.1.2.1.2.2.1.2.1'] = $text('ethernet0/9/0');
        $oid["$b.43.1.3.$a"] = $text('HWTC' . hex2bin('1234ABCD'));
        $oid["$b.43.1.3.$c"] = $text('ZTEG' . hex2bin('00C171A7'));
        $oid["$b.43.1.7.$a"] = $text('line-100M');
        $oid["$b.43.1.9.$a"] = $text('Kati 2 hyrja A');
        $oid["$b.43.1.9.$c"] = $text('byname');
        $oid["$b.45.1.4.$a"] = $text('HG8245H');
        $oid["$b.46.1.15.$a"] = [2, 1];
        $oid["$b.46.1.15.$c"] = [2, 2];
        $oid["$b.46.1.20.$a"] = [2, 1945];
        $oid["$b.46.1.21.$a"] = [2, 1];
        $oid["$b.46.1.22.$a"] = $text(pack('nC6', 2026, 11, 3, 8, 30, 5, 0));
        $oid["$b.46.1.24.$c"] = [2, 13];
        $oid["$b.51.1.1.$a"] = [2, 48];
        $oid["$b.51.1.4.$a"] = [2, -2337];
        $oid["$b.51.1.6.$a"] = [2, 7146];
        // An offline ONU has no optical reading.
        $oid["$b.51.1.4.$c"] = [2, 2147483647];
        return $oid;
    }

    private function startAgent(?array $oids = null): void
    {
        $dir = (string) getenv('RADIUSONE_TEST_DIR');
        $this->log = $dir . '/fake-olt-' . bin2hex(random_bytes(4)) . '.log';
        $this->snmpPort = random_int(20000, 40000);
        $this->telnetPort = $this->snmpPort + 1;
        $config = $dir . '/fake-olt-' . bin2hex(random_bytes(4)) . '.json';
        $ready = $config . '.ready';
        touch($this->log);
        file_put_contents($config, json_encode([
            'log' => $this->log, 'ready' => $ready, 'snmp_port' => $this->snmpPort, 'telnet_port' => $this->telnetPort,
            'community' => 'public-ro', 'user' => 'reader', 'password' => 'read-pass',
            'mac_table' => self::MAC_TABLE, 'oids' => $oids ?? self::oids(),
        ], JSON_THROW_ON_ERROR));
        $pipes = [];
        $this->agent = proc_open([PHP_BINARY, base_path('tests/fixtures/fake-olt.php'), $config], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($ready); $i++) {
            usleep(50000);
        }
        $this->assertFileExists($ready);
    }

    private function olt(array $overrides = []): array
    {
        $id = Olts::save(null, Olts::validated($overrides + [
            'name' => 'olt-1', 'model' => 'huawei_ma5800_x7', 'host' => '127.0.0.1', 'enabled' => '1',
            'snmp_version' => '2c', 'snmp_port' => (string) $this->snmpPort, 'snmp_community' => 'public-ro',
            'telnet_enabled' => '1', 'telnet_port' => (string) $this->telnetPort, 'telnet_user' => 'reader', 'telnet_password' => 'read-pass',
        ], null));
        return Olts::find($id);
    }

    private function customer(string $username): int
    {
        return $this->insertRow('customer_profiles', ['username' => $username, 'account_status' => 'active', 'enabled' => 1]);
    }

    private function openSession(string $username, string $mac): void
    {
        $this->insertRow('radacct', ['username' => $username, 'callingstationid' => $mac, 'acctstarttime' => date('Y-m-d H:i:s'), 'acctupdatetime' => date('Y-m-d H:i:s'), 'acctstoptime' => null, 'nasipaddress' => '10.0.0.1']);
    }

    public function test_it_reads_onus_and_macs_from_the_olt_and_links_the_client(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->customer('gtest');
        $this->openSession('gtest', '78:9A:18:06:A4:4A');

        $this->assertSame(['olts' => 1, 'failed' => 0, 'linked' => 1], OltSync::due());

        $onu = Db::row('SELECT * FROM olt_onus WHERE sn="HWTC1234ABCD"');
        $this->assertSame([0, 1, 0, 3], [(int) $onu['frame'], (int) $onu['slot'], (int) $onu['port'], (int) $onu['ont_id']]);
        $this->assertSame('online', $onu['status']);
        $this->assertSame('Kati 2 hyrja A', $onu['description']);
        $this->assertSame('HG8245H', $onu['onu_model']);
        $this->assertSame('line-100M', $onu['line_profile']);
        $this->assertSame(-23.37, (float) $onu['rx_dbm']);
        $this->assertSame(-28.54, (float) $onu['olt_rx_dbm']);
        $this->assertSame(1945, (int) $onu['distance_m']);
        $this->assertSame('2026-11-03 08:30:05', $onu['last_up_at']);

        $offline = Db::row('SELECT * FROM olt_onus WHERE sn="ZTEG00C171A7"');
        $this->assertSame('offline', $offline['status']);
        $this->assertNull($offline['rx_dbm']);
        $this->assertSame('Power off (dying gasp)', $offline['down_cause']);

        // Only the two MACs behind ONUs, read across the "More" page. The uplink's MAC is left out.
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM olt_macs WHERE olt_id=?', [$olt['id']]));
        $link = Db::row('SELECT * FROM customer_onus');
        $this->assertSame(['HWTC1234ABCD', 'mac', 'system'], [$link['sn'], $link['source'], $link['linked_by']]);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="link_onu"'));

        $saved = Olts::find((int) $olt['id']);
        $this->assertSame(1, (int) $saved['last_sync_ok']);
        $this->assertSame(1, (int) $saved['last_mac_ok']);
        $this->assertNull($saved['last_error']);
    }

    public function test_the_olt_only_ever_receives_reads(): void
    {
        $this->startAgent();
        OltSync::run($this->olt());
        $lines = file($this->log, FILE_IGNORE_NEW_LINES);
        $this->assertNotEmpty($lines);
        $types = array_unique(array_filter($lines, static fn (string $l) => str_starts_with($l, 'snmp')));
        // GETBULK. A SET would be a3.
        $this->assertSame(['snmp a5'], array_values($types));
        $commands = array_values(array_filter($lines, static fn (string $l) => str_starts_with($l, 'telnet-cmd')));
        $this->assertSame(['telnet-cmd enable', 'telnet-cmd scroll', 'telnet-cmd display mac-address all', 'telnet-cmd quit', 'telnet-cmd y'], $commands);
    }

    public function test_the_telnet_client_refuses_any_other_command(): void
    {
        $cli = new OltCli('127.0.0.1', 1, 'u', 'p');
        foreach (['undo ont', 'config', 'display mac-address all; reboot', 'ont delete 0 1', 'save'] as $command) {
            try {
                (new ReflectionMethod($cli, 'send'))->invoke($cli, $command);
                $this->fail("Sent: $command");
            } catch (RuntimeException $e) {
                $this->assertSame('This command is not allowed on the OLT.', $e->getMessage());
            }
        }
    }

    public function test_a_failed_read_keeps_the_last_list_and_says_why(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        OltSync::run($olt);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));

        // The wrong community gets no answer at all.
        Db::exec('UPDATE olts SET snmp_community=?,sync_requested_at=NOW() WHERE id=?', [Secrets::encrypt('wrong'), $olt['id']]);
        $broken = Olts::find((int) $olt['id']);
        $this->assertFalse(OltSync::run($broken + ['_' => 1]));
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
        $saved = Olts::find((int) $olt['id']);
        $this->assertSame(0, (int) $saved['last_sync_ok']);
        $this->assertStringContainsString('No SNMP response', $saved['last_error']);
    }

    public function test_an_empty_answer_keeps_the_last_list(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        OltSync::saveOnus($olt, [['frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 1, 'sn' => 'HWTC00000001', 'description' => '', 'status' => 'online']]);
        try {
            OltSync::saveOnus($olt, []);
            $this->fail('An empty list was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('listed no ONUs', $e->getMessage());
        }
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
    }

    public function test_an_onu_gone_from_the_olt_is_removed_from_the_list_but_the_clients_link_stays(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $customer = $this->customer('gtest');
        $row = ['frame' => 0, 'slot' => 1, 'port' => 0, 'description' => '', 'status' => 'online'];
        OltSync::saveOnus($olt, [$row + ['ont_id' => 1, 'sn' => 'HWTC00000001'], $row + ['ont_id' => 2, 'sn' => 'HWTC00000002']]);
        OltMapper::linkBySerial($customer, 'gtest', 'HWTC00000002', 'noc');
        sleep(1);
        OltSync::saveOnus($olt, [$row + ['ont_id' => 1, 'sn' => 'HWTC00000001']]);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
        $found = OltMapper::forCustomer($customer);
        $this->assertSame('HWTC00000002', $found['link']['sn']);
        $this->assertNull($found['onu']);
    }

    public function test_a_moved_onu_keeps_its_client(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $customer = $this->customer('gtest');
        OltSync::saveOnus($olt, [['frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 1, 'sn' => 'HWTC00000001', 'description' => '', 'status' => 'online']]);
        OltMapper::linkBySerial($customer, 'gtest', 'hwtc-00000001', 'noc');
        sleep(1);
        OltSync::saveOnus($olt, [['frame' => 0, 'slot' => 2, 'port' => 7, 'ont_id' => 9, 'sn' => 'HWTC00000001', 'description' => '', 'status' => 'online']]);
        $this->assertSame('0/2/7:9', Olts::position(OltMapper::forCustomer($customer)['onu']));
    }

    public function test_description_links_only_an_exact_and_unique_match(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->customer('byname');
        $this->customer('shared');
        $this->customer('other');
        $row = ['frame' => 0, 'slot' => 1, 'port' => 0, 'status' => 'online'];
        OltSync::saveOnus($olt, [
            $row + ['ont_id' => 1, 'sn' => 'HWTC00000001', 'description' => 'byname'],
            $row + ['ont_id' => 2, 'sn' => 'HWTC00000002', 'description' => 'shared'],
            $row + ['ont_id' => 3, 'sn' => 'HWTC00000003', 'description' => 'shared'],
            $row + ['ont_id' => 4, 'sn' => 'HWTC00000004', 'description' => 'other client'],
        ]);
        $this->assertSame(1, OltMapper::linkAutomatically());
        $link = Db::row('SELECT c.username,l.sn,l.source FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id');
        $this->assertSame(['byname', 'HWTC00000001', 'description'], [$link['username'], $link['sn'], $link['source']]);
    }

    public function test_a_mac_behind_two_onus_is_not_linked_automatically(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->customer('gtest');
        $this->openSession('gtest', '78-9A-18-06-A4-4A');
        $row = ['frame' => 0, 'slot' => 1, 'port' => 0, 'description' => '', 'status' => 'online'];
        OltSync::saveOnus($olt, [$row + ['ont_id' => 1, 'sn' => 'HWTC00000001'], $row + ['ont_id' => 2, 'sn' => 'HWTC00000002']]);
        OltSync::saveMacs($olt, [
            ['mac' => '789a1806a44a', 'vlan' => 100, 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 1],
            ['mac' => '789a1806a44a', 'vlan' => 200, 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 2],
        ]);
        $this->assertSame(0, OltMapper::linkAutomatically());
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM customer_onus'));
        $this->assertCount(2, array_values(OltMapper::macEvidence())[0]['onus']);
    }

    public function test_a_link_is_not_replaced_automatically_and_the_page_shows_the_difference(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $customer = $this->customer('gtest');
        $this->openSession('gtest', '789a.1806.a44a');
        $row = ['frame' => 0, 'slot' => 1, 'port' => 0, 'description' => '', 'status' => 'online'];
        OltSync::saveOnus($olt, [$row + ['ont_id' => 1, 'sn' => 'HWTC00000001'], $row + ['ont_id' => 2, 'sn' => 'HWTC00000002']]);
        OltSync::saveMacs($olt, [['mac' => '789a1806a44a', 'vlan' => 100, 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 2]]);
        OltMapper::linkBySerial($customer, 'gtest', 'HWTC00000001', 'noc');

        $this->assertSame(0, OltMapper::linkAutomatically());
        $this->assertSame('HWTC00000001', Db::value('SELECT sn FROM customer_onus'));

        $this->actingAsOperator();
        $this->get('/olts/mapping?filter=differs')->assertOk()->assertInertia(fn ($page) => $page
            ->component('olts/Mapping')
            ->where('counts.differs', 1)
            ->where('result.rows.0.username', 'gtest')
            ->where('result.rows.0.link.sn', 'HWTC00000001')
            ->where('result.rows.0.found.onus.0.sn', 'HWTC00000002'));
    }

    public function test_unlinking_is_final_until_someone_links_again(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->customer('gtest');
        $this->openSession('gtest', '78:9A:18:06:A4:4A');
        OltSync::run($olt);
        $this->assertSame(1, OltMapper::linkAutomatically());

        $this->actingAsOperator();
        $this->post('/clients/gtest/onu/unlink')->assertRedirect();
        $this->assertSame(0, OltMapper::linkAutomatically());
        $this->assertNull(OltMapper::forCustomer((int) Db::value('SELECT id FROM customer_profiles')));

        $this->post('/clients/gtest/onu', ['sn' => 'HWTC1234ABCD'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('manual', Db::value('SELECT source FROM customer_onus'));
        $this->post('/clients/gtest/onu', ['sn' => 'NOPE'])->assertSessionHas('flash.type', 'danger');
        $this->assertSame('HWTC1234ABCD', Db::value('SELECT sn FROM customer_onus'));
    }

    public function test_the_mac_table_parser_never_wipes_the_list_on_an_error_message(): void
    {
        $this->assertSame([], HuaweiMa5800::parseMacTable('  Failure: There is not any MAC address record'));
        $this->expectException(RuntimeException::class);
        HuaweiMa5800::parseMacTable('  % Unknown command, the error locates at \'^\'');
    }

    public function test_a_mac_table_in_an_unknown_layout_is_an_error_and_not_an_empty_list(): void
    {
        $this->expectExceptionMessage('layout the portal does not understand');
        HuaweiMa5800::parseMacTable("  F /S /P\n  gpon 789a-1806-a44a 0/1/0 ont 3 vlan 100\n");
    }

    public function test_serial_numbers(): void
    {
        $this->assertSame('HWTC1234ABCD', HuaweiMa5800::serial('HWTC' . hex2bin('1234abcd')));
        $this->assertSame('HWTC1234ABCD', HuaweiMa5800::serial('hwtc1234abcd'));
        $this->assertSame('789a1806a44a', OltMapper::mac('78:9A:18:06:A4:4A'));
        $this->assertNull(OltMapper::mac('203.0.113.10'));
        $this->assertNull(OltMapper::mac('not a mac at all'));
    }

    public function test_wrong_telnet_password_is_reported_and_snmp_still_works(): void
    {
        $this->startAgent();
        $olt = $this->olt(['telnet_password' => 'wrong-pass']);
        $this->assertFalse(OltSync::run($olt));
        $saved = Olts::find((int) $olt['id']);
        $this->assertSame(1, (int) $saved['last_sync_ok']);
        $this->assertSame(0, (int) $saved['last_mac_ok']);
        $this->assertStringContainsString('refused this telnet user', $saved['last_mac_error']);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM olt_onus'));
    }

    public function test_the_form_saves_secrets_sealed_and_never_shows_them(): void
    {
        $this->actingAsOperator();
        $input = [
            'name' => 'olt-web', 'model' => 'huawei_ma5800_x7', 'host' => '192.0.2.10', 'enabled' => true, 'snmp_version' => '2c', 'snmp_port' => '161',
            'snmp_community' => 'community-secret', 'telnet_enabled' => true, 'telnet_port' => '23', 'telnet_user' => 'user-secret', 'telnet_password' => 'password-secret',
        ];
        $this->post('/olts/save', $input)->assertSessionHas('flash.type', 'success');
        $stored = Db::row('SELECT * FROM olts');
        foreach (['snmp_community' => 'community-secret', 'telnet_user' => 'user-secret', 'telnet_password' => 'password-secret'] as $column => $plain) {
            $this->assertStringStartsWith('enc:v1:', $stored[$column]);
            $this->assertSame($plain, Secrets::reveal($stored[$column]));
        }
        $this->assertNotNull($stored['sync_requested_at']);

        $page = $this->get('/olts/' . $stored['id'] . '/edit')->assertOk();
        foreach (['community-secret', 'user-secret', 'password-secret', 'enc:v1:'] as $secret) {
            $page->assertDontSee($secret, false);
        }
        $audit = (string) Db::value('SELECT after_json FROM audit_logs WHERE entity_type="olt"');
        $this->assertStringNotContainsString('secret', $audit);

        // Blank keeps what was saved.
        $this->post('/olts/' . $stored['id'] . '/save', ['snmp_community' => '', 'telnet_user' => '', 'telnet_password' => '', 'name' => 'renamed'] + $input)->assertSessionHas('flash.type', 'success');
        $after = Db::row('SELECT * FROM olts');
        $this->assertSame('renamed', $after['name']);
        $this->assertSame('password-secret', Secrets::reveal($after['telnet_password']));
    }

    public function test_the_form_refuses_bad_settings(): void
    {
        $this->actingAsOperator();
        $good = ['name' => 'x', 'model' => 'huawei_ma5800_x7', 'host' => '192.0.2.10', 'snmp_version' => '2c', 'snmp_port' => '161', 'snmp_community' => 'c', 'telnet_port' => '23'];
        foreach ([['model' => 'unknown'], ['host' => 'olt.example.com'], ['name' => ''], ['snmp_community' => ''], ['snmp_port' => '0'], ['telnet_enabled' => true]] as $bad) {
            $this->post('/olts/save', $bad + $good)->assertSessionHas('flash.type', 'danger');
        }
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM olts'));
    }

    public function test_pages_and_test_button_work_against_the_olt(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        OltSync::run($olt);
        $this->actingAsOperator();
        $this->get('/olts')->assertOk()->assertInertia(fn ($p) => $p->component('olts/Index')->where('olts.0.onus', 2)->where('olts.0.online', 1)->where('olts.0.ok', true));
        $this->get('/olts/' . $olt['id'])->assertOk()->assertInertia(fn ($p) => $p->component('olts/Show')->where('counts.total', 2)->where('result.rows.0.position', '0/1/0:3'));
        $this->get('/olts/' . $olt['id'] . '?q=ZTEG')->assertInertia(fn ($p) => $p->where('result.total', 1));
        $this->get('/olts/' . $olt['id'] . '?status=offline')->assertInertia(fn ($p) => $p->where('result.rows.0.sn', 'ZTEG00C171A7'));
        $this->post('/olts/' . $olt['id'] . '/test')->assertSessionHas('flash.type', 'success');
        $this->assertSame(1, substr_count(implode("\n", array_filter(file($this->log, FILE_IGNORE_NEW_LINES), static fn ($l) => $l === 'snmp a0')), 'snmp a0'));
        $this->assertStringContainsString('MA5800', (string) Db::value('SELECT system_description FROM olts'));
        $this->post('/olts/' . $olt['id'] . '/sync')->assertSessionHas('flash.type', 'success');
        $this->assertNotNull(Db::value('SELECT sync_requested_at FROM olts'));
    }

    public function test_the_client_page_shows_its_onu(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->insertRow('radcheck', ['username' => 'gtest', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'x']);
        $this->customer('gtest');
        $this->openSession('gtest', '78:9A:18:06:A4:4A');
        OltSync::run($olt);
        OltMapper::linkAutomatically();
        $this->actingAsOperator();
        $this->get('/clients/gtest/edit')->assertOk()->assertInertia(fn ($p) => $p
            ->where('client.onu.link.sn', 'HWTC1234ABCD')
            ->where('client.onu.onu.status', 'online')
            ->where('client.onu.link.source', 'Found from the session MAC'));
    }

    public function test_permissions(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->customer('gtest');
        $this->actingAsOperator('view');
        $this->get('/olts')->assertOk();
        $this->get('/olts/' . $olt['id'])->assertOk();
        $this->get('/olts/create')->assertForbidden();
        $this->post('/olts/save', [])->assertForbidden();
        $this->post('/olts/' . $olt['id'] . '/sync')->assertForbidden();
        $this->post('/olts/' . $olt['id'] . '/delete')->assertForbidden();
        $this->post('/clients/gtest/onu', ['sn' => 'X'])->assertForbidden();
        $this->post('/clients/gtest/onu/unlink')->assertForbidden();
    }

    public function test_removing_an_olt_removes_only_the_portal_data(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->customer('gtest');
        $this->openSession('gtest', '78:9A:18:06:A4:4A');
        OltSync::run($olt);
        OltMapper::linkAutomatically();
        $this->actingAsOperator();
        $this->post('/olts/' . $olt['id'] . '/delete')->assertSessionHas('flash.type', 'success');
        foreach (['olts', 'olt_onus', 'olt_macs', 'customer_onus'] as $table) {
            $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM $table"), $table);
        }
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM customer_profiles'));
    }

    public function test_the_job_reads_only_what_is_due(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->assertSame(1, OltSync::due()['olts']);
        $this->assertSame(0, OltSync::due()['olts']);
        Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE id=?', [$olt['id']]);
        $this->assertSame(1, OltSync::due()['olts']);
        Db::exec('UPDATE olts SET enabled=0,sync_requested_at=NOW() WHERE id=?', [$olt['id']]);
        $this->assertSame(0, OltSync::due()['olts']);
        $this->artisan('radiusone:poll-olts')->assertExitCode(0);
    }
}
