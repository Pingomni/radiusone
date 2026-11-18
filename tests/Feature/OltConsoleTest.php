<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\Olt\OltConsole;
use App\Support\Olt\Olts;
use Tests\TestCase;

/**
 * The OLT's live console: the console service (radiusone:olt-console) holds a telnet session to the pretend OLT, and
 * the portal passes keys in and output out. Runs the service as its own process, as on a server.
 */
final class OltConsoleTest extends TestCase
{
    /** @var resource|null */
    private $service = null;
    /** @var resource|null */
    private $agent = null;
    private string $log = '';
    private int $telnetPort = 0;

    protected function tearDown(): void
    {
        foreach ([$this->service, $this->agent] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        putenv('RADIUSONE_CONSOLE_SOCKET');
        parent::tearDown();
    }

    private function startAgent(): void
    {
        $dir = (string) getenv('RADIUSONE_TEST_DIR');
        $this->log = $dir . '/console-olt-' . bin2hex(random_bytes(4)) . '.log';
        $snmp = random_int(20000, 40000);
        $this->telnetPort = $snmp + 1;
        $config = $dir . '/console-olt-' . bin2hex(random_bytes(4)) . '.json';
        touch($this->log);
        file_put_contents($config, json_encode(['log' => $this->log, 'ready' => $config . '.ready', 'snmp_port' => $snmp, 'telnet_port' => $this->telnetPort, 'community' => 'public-ro', 'user' => 'manager', 'password' => 'manage-pass', 'mac_table' => "  Total: 0\n", 'oids' => []]));
        $this->agent = proc_open([PHP_BINARY, base_path('tests/fixtures/fake-olt.php'), $config], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && !file_exists($config . '.ready'); $i++) {
            usleep(50000);
        }
        $this->assertFileExists($config . '.ready', 'The pretend OLT did not start.');
    }

    private function startService(): void
    {
        $socket = getenv('RADIUSONE_TEST_DIR') . '/console-' . bin2hex(random_bytes(4)) . '.sock';
        putenv('RADIUSONE_CONSOLE_SOCKET=' . $socket);
        // The test's own settings (its database and keys), as phpunit.xml forces them for this process.
        $env = array_filter([...getenv(), ...$_ENV, ...$_SERVER], static fn ($v, $k) => is_string($v) && is_string($k), ARRAY_FILTER_USE_BOTH);
        $env['RADIUSONE_CONSOLE_SOCKET'] = $socket;
        $this->service = proc_open([PHP_BINARY, base_path('artisan'), 'radiusone:olt-console'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', getenv('RADIUSONE_TEST_DIR') . '/console-service.err', 'a']], $pipes, base_path(), $env);
        for ($i = 0; $i < 100 && !file_exists($socket); $i++) {
            usleep(50000);
        }
        $this->assertFileExists($socket, 'The console service did not start: ' . @file_get_contents(getenv('RADIUSONE_TEST_DIR') . '/console-service.err'));
    }

    /** @return array<string, mixed> */
    private function olt(bool $manage = true): array
    {
        return Olts::find(Olts::save(null, Olts::validated([
            'name' => 'olt-1', 'model' => 'huawei_ma5800_x7', 'host' => '127.0.0.1', 'enabled' => '1', 'snmp_version' => '2c', 'snmp_port' => '161', 'snmp_community' => 'public-ro',
            'telnet_enabled' => '1', 'telnet_port' => (string) $this->telnetPort, 'telnet_user' => 'manager', 'telnet_password' => 'manage-pass', 'manage_enabled' => $manage ? '1' : '',
        ], null)));
    }

    /** Polls until $until says the output so far is complete, as the browser does. */
    private function read(string $url, string $input, int &$offset, callable $until): string
    {
        $all = '';
        $first = true;
        for ($i = 0; $i < 60; $i++) {
            $answer = $this->postJson($url, ['input' => $first ? base64_encode($input) : '', 'offset' => $offset, 'cols' => 120, 'rows' => 30])->assertOk()->json();
            $first = false;
            $this->assertTrue($answer['ok'], (string) ($answer['error'] ?? '') . ' after: ' . json_encode($all) . ' log: ' . @file_get_contents($this->log) . ' service: ' . @file_get_contents(getenv('RADIUSONE_TEST_DIR') . '/console-service.err'));
            $all .= base64_decode($answer['data']);
            $offset = $answer['offset'];
            if ($until($all, $answer)) {
                return $all;
            }
            usleep(100000);
        }
        $this->fail('No answer in time. Got: ' . $all);
    }

    public function test_a_live_session_logs_in_out_of_sight_and_passes_keys_and_output(): void
    {
        $this->startAgent();
        $this->startService();
        $olt = $this->olt();
        $this->insertRow('olt_onus', ['olt_id' => $olt['id'], 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 3, 'sn' => 'HWTC1234ABCD', 'description' => 'Kati 2 hyrja A', 'status' => 'online', 'active' => 1, 'seen_at' => date('Y-m-d H:i:s')]);
        $owner = $this->actingAsOperator();
        $open = $this->postJson("/olts/{$olt['id']}/console", ['cols' => 120, 'rows' => 30])->assertOk()->assertJsonPath('ok', true);
        $session = (string) $open->json('session');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $session);
        $url = "/olts/{$olt['id']}/console/$session";
        $offset = 0;

        // The login happens in the service: the browser sees the banner and the prompt, never the user or password.
        $seen = $this->read($url, '', $offset, static fn (string $all, array $a) => $a['state'] === 'ready' && str_contains($all, 'MA5800-X7#'));
        $this->assertStringContainsString('Connected to olt-1', $seen);
        $this->assertStringNotContainsString('manage-pass', $seen);
        $this->assertStringNotContainsString('manager', $seen);

        // Keys go in as typed, Enter as CR LF, and the OLT's answer comes out.
        $seen = $this->read($url, "config\rinterface gpon 0/1\rdisplay ont info 0 3\r", $offset, static fn (string $all) => str_contains($all, 'Kati 2 hyrja A'));
        $this->assertStringContainsString('(config-if-gpon-0/1)#', $seen);
        // Each line typed is in the audit log, under the operator's name, with secrets masked.
        $this->read($url, "snmp-agent community read s3cret\r", $offset, static fn (string $all) => str_contains($all, 'Unknown command'));
        $logged = Db::select('SELECT actor_username,after_json FROM audit_logs WHERE action="olt_terminal" ORDER BY id');
        $commands = array_merge(...array_map(static fn (array $r) => json_decode((string) $r['after_json'], true)['commands'], $logged));
        $this->assertSame(['config', 'interface gpon 0/1', 'display ont info 0 3', 'snmp-agent community read ******'], $commands);
        $this->assertNotSame('system', $logged[0]['actor_username']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="olt_console"'));

        // Another operator cannot use this session, nor can anyone once it is closed.
        $this->actingAsOperator();
        $this->postJson($url, ['input' => base64_encode("display board 0\r")])->assertJsonPath('ok', false)->assertJsonPath('ended', true);
        $this->withSession(['user_id' => (int) $owner['id'], 'session_version' => (int) $owner['session_version'], 'reauth_at' => time()]);
        $this->postJson("$url/close")->assertJsonPath('ok', true);
        $this->postJson($url, ['input' => ''])->assertJsonPath('ok', false)->assertJsonPath('ended', true);
        $this->assertSame(0, OltConsole::request(['op' => 'ping'])['sessions']);
    }

    public function test_who_may_open_a_console_and_what_happens_without_the_service(): void
    {
        $this->startAgent();
        $olt = $this->olt();
        $this->actingAsOperator();
        // No service on this server: the page falls back to the line terminal.
        putenv('RADIUSONE_CONSOLE_SOCKET=' . getenv('RADIUSONE_TEST_DIR') . '/no-console.sock');
        $this->postJson("/olts/{$olt['id']}/console")->assertJsonPath('ok', false)->assertJsonPath('unavailable', true);
        $this->startService();
        $this->actingAsOperator('noc');
        $this->postJson("/olts/{$olt['id']}/console")->assertForbidden();
        $this->actingAsOperator('view');
        $this->postJson("/olts/{$olt['id']}/console")->assertForbidden();
        $this->actingAsOperator();
        $this->postJson("/olts/{$olt['id']}/console/" . str_repeat('a', 32), ['input' => ''])->assertJsonPath('ended', true);
        Db::exec('UPDATE olts SET manage_enabled=0 WHERE id=?', [$olt['id']]);
        $this->postJson("/olts/{$olt['id']}/console")->assertJsonPath('ok', false)->assertJsonPath('error', fn (string $e) => str_contains($e, 'Allow changes'));
        // A wrong saved password: the session fails with a reason, and the service holds nothing for it.
        Db::exec('UPDATE olts SET manage_enabled=1 WHERE id=?', [$olt['id']]);
        $bad = Olts::find(Olts::save((int) $olt['id'], Olts::validated(['name' => 'olt-1', 'model' => 'huawei_ma5800_x7', 'host' => '127.0.0.1', 'enabled' => '1', 'snmp_version' => '2c', 'snmp_port' => '161', 'snmp_community' => 'public-ro', 'telnet_enabled' => '1', 'telnet_port' => (string) $this->telnetPort, 'telnet_user' => 'manager', 'telnet_password' => 'wrong-pass', 'manage_enabled' => '1'], $olt)));
        $session = (string) $this->postJson("/olts/{$bad['id']}/console")->assertJsonPath('ok', true)->json('session');
        $offset = 0;
        $state = '';
        for ($i = 0; $i < 60 && $state !== 'failed'; $i++) {
            $answer = $this->postJson("/olts/{$bad['id']}/console/$session", ['offset' => $offset])->json();
            $state = $answer['ok'] ? $answer['state'] : 'gone';
            $offset = $answer['offset'] ?? $offset;
            usleep(100000);
        }
        $this->assertSame('failed', $state);
        $this->assertSame(0, OltConsole::request(['op' => 'ping'])['sessions']);
    }
}
