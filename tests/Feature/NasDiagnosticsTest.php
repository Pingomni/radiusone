<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\Firewall;
use App\Support\NasDiagnostics;
use App\Support\Settings;
use Tests\TestCase;

final class NasDiagnosticsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = (string) getenv('RADIUSONE_TEST_DIR') . '/nas-diag';
        @mkdir($this->dir);
        foreach (['capture.txt', 'capture.started', 'capture.done', 'helper.out', 'helper-called'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        $helper = $this->dir . '/helper';
        file_put_contents($helper, "#!/bin/sh\necho \"\$1\" >> \"$this->dir/helper-called\"\n[ \"\$1\" = log ] && cat \"$this->dir/helper.out\"\nexit 0\n");
        chmod($helper, 0755);
        file_put_contents($this->dir . '/helper.out', '');
        NasDiagnostics::$command = [$helper];
        NasDiagnostics::$dir = $this->dir;
        NasDiagnostics::$udpPorts = [1812 => true, 1813 => true];
    }

    protected function tearDown(): void
    {
        NasDiagnostics::$command = null;
        NasDiagnostics::$dir = null;
        NasDiagnostics::$udpPorts = null;
        parent::tearDown();
    }

    private function nas(string $ip = '10.77.0.2', string $name = 'r1', string $secret = 'rightsecret'): array
    {
        $id = $this->insertRow('nas', ['nasname' => $ip, 'shortname' => $name, 'secret' => $secret]);
        $this->insertRow('nas_metadata', ['nas_id' => $id, 'vendor' => 'mikrotik', 'enabled' => 1, 'config_revision' => 1, 'applied_revision' => 1]);

        return ['id' => $id, 'nasname' => $ip, 'shortname' => $name, 'secret' => $secret, 'enabled' => 1, 'config_revision' => 1, 'applied_revision' => 1, 'updated_at' => '2020-01-01 00:00:00'];
    }

    private static function logDate(int $at): string
    {
        return date('D M j H:i:s Y', $at);
    }

    /** tcpdump's -x output for a RADIUS packet, as the real one prints it. */
    private static function dump(string $from, int $fromPort, string $to, int $toPort, string $word, string $radius): string
    {
        $ip = "\x45\0" . pack('n', 28 + strlen($radius)) . str_repeat("\0", 16) . pack('nnnn', $fromPort, $toPort, 8 + strlen($radius), 0) . $radius;
        $line = "1793629427.218826 eth0  In  IP $from.$fromPort > $to.$toPort: RADIUS, $word, id: 0x36 length: " . strlen($radius) . "\n";
        foreach (str_split(bin2hex($ip), 32) as $i => $chunk) {
            $line .= sprintf("\t0x%04x:  %s\n", $i * 16, implode(' ', str_split($chunk, 4)));
        }

        return $line;
    }

    private static function accounting(string $secret): string
    {
        $attributes = chr(40) . chr(6) . pack('N', 1);
        $head = "\x04\x36" . pack('n', 20 + strlen($attributes));

        return $head . md5($head . str_repeat("\0", 16) . $attributes . $secret, true) . $attributes;
    }

    private static function signedLogin(string $secret): string
    {
        $head = "\x01\x36" . pack('n', 38);
        $auth = str_repeat('a', 16);
        $blank = $head . $auth . chr(80) . chr(18) . str_repeat("\0", 16);

        return $head . $auth . chr(80) . chr(18) . hash_hmac('md5', $blank, $secret, true);
    }

    public function test_real_freeradius_lines_become_events_and_a_rejected_reason_keeps_its_parentheses(): void
    {
        $now = time();
        $date = self::logDate($now - 60);
        $parsed = NasDiagnostics::parseLog(implode("\n", [
            '#auth-log yes 1700000000',
            "$date : Error: Ignoring request to auth address * port 1812 bound to server default from unknown client 10.77.0.3 port 45425 proto udp",
            "$date : Error: Ignoring request to auth address * port 1812 bound to server default from unknown client 10.77.0.3 port 45426 proto udp",
            "$date : Auth: (0) Login OK: [] (from client r1 port 0)",
            "$date : Auth: (1) Login incorrect (pap: Cleartext password does not match \"known good\" password): [] (from client my router port 0 cli aa)",
            ($now - 30) . '.000000 host kernel: radiusone-radius-drop: IN=eth0 OUT= SRC=203.0.113.9 DST=10.77.0.1 LEN=62 PROTO=UDP SPT=5000 DPT=1812 LEN=42',
            self::logDate($now - 90000) . ' : Auth: (2) Login OK: [] (from client old port 0)',
            'anything else',
        ]), $now);

        $this->assertTrue($parsed['authLog']);
        $found = array_column($parsed['events'], null, 'kind');
        $this->assertSame(2, $found['unknown_client']['count']);
        $this->assertSame('10.77.0.3', $found['unknown_client']['source']);
        $this->assertSame('r1', $found['login_ok']['source']);
        $this->assertSame('my router', $found['login_rejected']['source']);
        $this->assertStringContainsString('known good', $found['login_rejected']['reason']);
        $this->assertSame('203.0.113.9', $found['firewall_drop']['source']);
        $this->assertCount(4, $parsed['events'], 'The line older than a day is left out.');
    }

    public function test_addresses_in_the_nas_list_are_not_reported_as_unknown(): void
    {
        $this->nas();
        $log = NasDiagnostics::parseLog(implode("\n", [
            self::logDate(time() - 5) . ' : Error: Ignoring request to auth address * port 1812 bound to server default from unknown client 10.77.0.2 port 1 proto udp',
            self::logDate(time() - 5) . ' : Error: Ignoring request to auth address * port 1812 bound to server default from unknown client 10.77.0.3 port 1 proto udp',
        ]));
        $log += ['available' => true];
        $rows = NasDiagnostics::unknownRouters($log);
        $this->assertSame(['10.77.0.3'], array_column($rows, 'address'));
        $this->assertSame('Refused by FreeRADIUS', $rows[0]['how']);
    }

    public function test_the_secret_check_recognises_right_and_wrong_for_accounting_and_signed_logins(): void
    {
        $ip = static fn (string $radius): string => "\x45" . str_repeat("\0", 19) . str_repeat("\0", 8) . $radius;
        $this->assertTrue(NasDiagnostics::secretMatches($ip(self::accounting('s3')), 's3'));
        $this->assertFalse(NasDiagnostics::secretMatches($ip(self::accounting('other')), 's3'));
        $this->assertTrue(NasDiagnostics::secretMatches($ip(self::signedLogin('s3')), 's3'));
        $this->assertFalse(NasDiagnostics::secretMatches($ip(self::signedLogin('other')), 's3'));
        // A login without a signature, and a cut-off packet, cannot be judged.
        $this->assertNull(NasDiagnostics::secretMatches($ip("\x01\x36\x00\x14" . str_repeat('a', 16)), 's3'));
        $this->assertNull(NasDiagnostics::secretMatches($ip("\x04\x36\x00\xff" . str_repeat('a', 16)), 's3'));
    }

    public function test_capture_counts_requests_replies_and_secret_per_address(): void
    {
        $out = self::dump('10.77.0.2', 5000, '10.77.0.1', 1813, 'Accounting-Request (4)', self::accounting('rightsecret'))
            . self::dump('10.77.0.1', 1813, '10.77.0.2', 5000, 'Accounting-Response (5)', str_repeat("\0", 20))
            . self::dump('10.77.0.2', 5001, '10.77.0.1', 1812, 'Access-Request (1)', self::signedLogin('wrong'))
            . "1793629422.873782 rlv0  Out IP 10.77.0.1.1812 > 10.77.0.2.59519: RADIUS, Access-Accept (2), id: 0xf6 length: 38\n"
            . "1793629423.000000 rlv0  In  IP 10.77.0.3.45425 > 10.77.0.1.1812: RADIUS, Access-Request (1), id: 0xa6 length: 62\n";
        $peers = NasDiagnostics::parseCapture($out, '10.77.0.2/32', 'rightsecret');
        $this->assertSame(1, $peers['10.77.0.2']['logins']);
        $this->assertSame(1, $peers['10.77.0.2']['accounting']);
        $this->assertSame(1, $peers['10.77.0.2']['accountingReplies']);
        $this->assertSame(1, $peers['10.77.0.2']['accepted']);
        $this->assertSame(1, $peers['10.77.0.2']['secretOk']);
        $this->assertSame(1, $peers['10.77.0.2']['secretBad']);
        $this->assertSame(1, $peers['10.77.0.3']['logins']);
        $this->assertSame(0, $peers['10.77.0.3']['secretBad'], 'Only the NAS under test is checked.');
    }

    public function test_each_outcome_of_the_packet_test_has_its_own_conclusion(): void
    {
        $nas = $this->nas();
        $log = ['available' => true, 'error' => '', 'authLog' => true, 'authLogChanged' => 0, 'events' => []];
        $zero = ['logins' => 0, 'accepted' => 0, 'rejected' => 0, 'challenged' => 0, 'accounting' => 0, 'accountingReplies' => 0, 'otherReplies' => 0, 'secretOk' => 0, 'secretBad' => 0];
        $title = fn (array $counts, array $others = []): string => NasDiagnostics::verdict($counts + $zero, $others, $nas, $log)['title'];

        $this->assertSame('Nothing arrived from 10.77.0.2', $title([]));
        $this->assertStringContainsString('other addresses', $title([], [['address' => '10.77.0.3', 'requests' => 4]]));
        $this->assertSame('The shared secret is wrong', $title(['accounting' => 2, 'secretBad' => 2]));
        $this->assertSame('Requests arrive, but FreeRADIUS does not answer', $title(['logins' => 3]));
        $this->assertStringContainsString('rejects the logins', $title(['logins' => 2, 'rejected' => 2]));
        $this->assertSame('Accounting requests get no reply', $title(['logins' => 1, 'accepted' => 1, 'accounting' => 2]));
        $this->assertSame('Logins work', $title(['logins' => 1, 'accepted' => 1]));
        $this->assertSame('The server receives requests and replies', $title(['logins' => 1, 'accepted' => 1, 'accounting' => 1, 'accountingReplies' => 1]));

        $withUnknown = $log;
        $withUnknown['events'] = [['kind' => 'unknown_client', 'source' => '10.77.0.2', 'at' => time(), 'reason' => '', 'count' => 3]];
        $this->assertStringContainsString('refused this address', NasDiagnostics::verdict(['logins' => 3] + $zero, [], $nas, $withUnknown)['title']);
    }

    public function test_the_firewall_verdict_follows_rule_order_routers_and_the_default_drop(): void
    {
        $this->assertSame('off', Firewall::radiusVerdict('10.77.0.2')['state']);

        $rule = static fn (int $id, string $action, string $source, string $ports = '1812,1813', string $protocol = 'udp', array $more = []): array => $more + ['id' => $id, 'action' => $action, 'protocol' => $protocol, 'source' => $source, 'ports' => $ports, 'enabled' => 1, 'expires_at' => null];
        $save = static function (array $rules, array $routers): void {
            Settings::save('firewall_active', (string) json_encode(['id' => 'x', 'config' => ['enabled' => true, 'management_networks' => []], 'rules' => $rules, 'routers' => $routers]));
        };
        $save([$rule(1, 'accept', 'routers')], ['10.77.0.2/32']);
        file_put_contents((string) getenv('RADIUSONE_TEST_DIR') . '/firewall-status/status.json', json_encode(['state' => 'on']));

        $this->assertSame('allowed', Firewall::radiusVerdict('10.77.0.2')['state']);
        $this->assertSame('blocked', Firewall::radiusVerdict('10.77.0.3')['state']);
        $this->assertSame('the default drop rule', Firewall::radiusVerdict('10.77.0.3')['by']);

        $save([$rule(2, 'drop', '10.77.0.0/24'), $rule(1, 'accept', 'routers')], ['10.77.0.2/32']);
        $this->assertSame('blocked', Firewall::radiusVerdict('10.77.0.2')['state']);

        // A paused, expired or TCP-only rule decides nothing, and only one of the two ports may be open.
        $save([$rule(2, 'drop', '', '1812,1813', 'udp', ['enabled' => 0]), $rule(3, 'drop', '', '1812,1813', 'udp', ['expires_at' => '2020-01-01 00:00:00']), $rule(4, 'accept', '', '1812', 'tcp'), $rule(5, 'accept', '', '1812-1812')], []);
        $verdict = Firewall::radiusVerdict('10.77.0.2');
        $this->assertSame('blocked', $verdict['state']);
        $this->assertSame([1813], $verdict['ports']);
    }

    public function test_rendered_rules_log_radius_drops_after_all_rules(): void
    {
        Firewall::ensureTemplate('203.0.113.5');
        $draft = Firewall::draft();
        $draft['config']['enabled'] = true;
        $script = Firewall::render($draft, [22]);
        $this->assertStringContainsString('udp dport { 1812, 1813 } limit rate 12/minute log prefix "radiusone-radius-drop: "', $script);
        $this->assertGreaterThan(strpos($script, 'comment "rule'), strpos($script, 'radiusone-radius-drop'));
    }

    public function test_the_tab_answers_without_ever_sending_the_secret_and_the_address_can_be_suggested(): void
    {
        $this->actingAsOperator();
        $nas = $this->nas();
        file_put_contents($this->dir . '/helper.out', "#auth-log no 0\n" . self::logDate(time() - 5) . " : Error: Ignoring request to auth address * port 1812 bound to server default from unknown client 10.77.0.3 port 1 proto udp\n");

        $response = $this->getJson('/nas/' . $nas['id'] . '/diagnostics')->assertOk();
        $this->assertStringNotContainsString('rightsecret', $response->getContent());
        $checks = array_column($response->json('checks'), 'state', 'key');
        $this->assertSame('ok', $checks['enabled']);
        $this->assertSame('ok', $checks['loaded']);
        $this->assertSame('ok', $checks['ports']);
        $this->assertSame('ok', $checks['firewall']);
        $this->assertSame('info', $checks['logins']);
        $this->assertSame(['10.77.0.3'], array_column($response->json('unknown'), 'address'));
        $this->assertSame('none', $response->json('capture.state'));

        $this->getJson('/nas/unknown-routers')->assertOk()->assertJsonPath('0.address', '10.77.0.3');
        $this->get('/nas/create?nasname=10.77.0.3')->assertOk()->assertInertia(fn ($page) => $page->where('device.nasname', '10.77.0.3'));
        $this->get('/nas/create?nasname=bad"value')->assertOk()->assertInertia(fn ($page) => $page->where('device.nasname', ''));
    }

    public function test_a_problem_in_the_log_turns_the_check_red_but_not_once_the_nas_was_saved_after_it(): void
    {
        $nas = $this->nas();
        $event = ['kind' => 'unknown_client', 'source' => '10.77.0.2', 'at' => time() - 60, 'reason' => '', 'count' => 2];
        $log = ['available' => true, 'error' => '', 'authLog' => false, 'authLogChanged' => 0, 'events' => [$event]];
        $states = static fn (array $row): array => array_column(NasDiagnostics::checks($row, $log), 'state', 'key');
        $this->assertSame('fail', $states($nas)['log']);
        $this->assertSame('ok', $states(['updated_at' => date('Y-m-d H:i:s')] + $nas)['log']);
        $this->assertCount(1, NasDiagnostics::recentErrors($nas, $log));
    }

    public function test_the_packet_test_needs_manage_rights_and_starts_the_helper(): void
    {
        $this->actingAsOperator();
        $nas = $this->nas();
        $this->post('/nas/' . $nas['id'] . '/diagnostics/capture')->assertRedirect('/nas/' . $nas['id'] . '/edit?tab=diagnostics');
        $this->assertStringContainsString('capture', (string) file_get_contents($this->dir . '/helper-called'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="nas.packet_test"'));
    }

    public function test_running_and_finished_results_come_from_the_helpers_files(): void
    {
        $nas = $this->nas();
        $log = ['available' => true, 'error' => '', 'authLog' => false, 'authLogChanged' => 0, 'events' => []];
        file_put_contents($this->dir . '/capture.started', (string) time());
        file_put_contents($this->dir . '/capture.txt', self::dump('10.77.0.2', 5000, '10.77.0.1', 1813, 'Accounting-Request (4)', self::accounting('other')));
        $running = NasDiagnostics::captureResult($nas, $log);
        $this->assertSame('running', $running['state']);
        $this->assertGreaterThan(0, $running['remaining']);
        $this->assertSame('The shared secret is wrong', $running['verdict']['title']);

        touch($this->dir . '/capture.done');
        $this->assertSame('done', NasDiagnostics::captureResult($nas, $log)['state']);

        unlink($this->dir . '/capture.done');
        file_put_contents($this->dir . '/capture.started', (string) (time() - 200));
        $this->assertSame('failed', NasDiagnostics::captureResult($nas, $log)['state']);
    }
}
