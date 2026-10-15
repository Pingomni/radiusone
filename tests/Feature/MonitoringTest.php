<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use Tests\TestCase;

final class MonitoringTest extends TestCase
{
    private function nas(): int
    {
        Db::exec('INSERT INTO nas (nasname, shortname, type, secret) VALUES ("192.0.2.10", "edge-1", "mikrotik", "s")');
        return (int) Db::pdo()->lastInsertId();
    }

    public function test_overview_page_and_live_json(): void
    {
        $this->actingAsOperator('view');
        $id = $this->nas();
        $this->get('/monitoring')->assertOk()->assertInertia(fn ($page) => $page
            ->component('monitoring/Index')
            ->has('overview.metrics', 4)
            ->where('overview.nas.0.id', $id)
            ->where('overview.nas.0.setupSnmp', false)
            ->has('overview.jobs')
            ->where('lastPoll', null));

        $this->getJson('/monitoring/live')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('nas.0.name', 'edge-1')
            ->assertJsonStructure(['metrics', 'server' => ['host', 'items'], 'nas', 'jobs']);
    }

    public function test_server_page_and_live_json(): void
    {
        $this->actingAsOperator('view');
        $this->get('/monitoring/server')->assertOk()->assertInertia(fn ($page) => $page
            ->component('monitoring/Server')
            ->has('status.gauges', 5)
            ->where('history.available', true));
        $this->getJson('/monitoring/server/live')->assertOk()->assertJsonStructure(['gauges', 'services', 'system', 'temperatures']);
        $this->get('/nas/0/monitor')->assertRedirect('/monitoring/server');
    }

    public function test_nas_monitor_without_snmp_and_history_charts(): void
    {
        $this->actingAsOperator();
        $id = $this->nas();
        $now = time();
        foreach ([180, 120, 60] as $ago) {
            $this->insertRow('nas_snmp_samples', ['nas_id' => $id, 'if_index' => 2, 'if_name' => 'ether1', 'sampled_at' => $now - $ago, 'in_bps' => 2_000_000, 'out_bps' => 500_000]);
            $this->insertRow('nas_snmp_health_samples', ['nas_id' => $id, 'sampled_at' => $now - $ago, 'cpu_pct' => 42.5, 'mem_used' => 512, 'mem_total' => 1024]);
        }

        $this->get('/nas/' . $id . '/monitor')->assertOk()->assertInertia(fn ($page) => $page
            ->component('monitoring/Nas')
            ->where('snapshot.status', 'off')
            ->where('snapshot.setup', '/nas/' . $id . '/edit?tab=snmp')
            ->where('history.selected', 2)
            ->where('history.traffic.data.chart.title', 'ether1')
            ->where('history.health.data.chart.unit', 'pct')
            ->has('oids'));

        $this->getJson('/nas/' . $id . '/monitor/graph?range=1h&if=2')->assertOk()
            ->assertJsonPath('chart.series.0.key', 'in')
            ->assertJsonPath('chart.max', fn ($max) => $max >= 2_000_000);
        $this->getJson('/nas/' . $id . '/monitor/health?range=bogus')->assertOk()->assertJsonPath('chart.max', 100);
        $this->getJson('/nas/' . $id . '/monitor/temperature')->assertOk()->assertJsonStructure(['message']);
        $this->getJson('/nas/' . $id . '/monitor/live')->assertOk()->assertJsonPath('status', 'off');
        $this->getJson('/nas/999/monitor/live')->assertNotFound();
    }

    public function test_live_endpoints_answer_401_when_signed_out(): void
    {
        $this->getJson('/monitoring/live')->assertUnauthorized();
        $this->get('/monitoring/live', ['X-Live-Fragment' => '1'])->assertUnauthorized();
    }

    public function test_poll_rejects_external_return_paths(): void
    {
        $this->actingAsOperator('view');
        $this->post('/monitoring/poll', ['target' => 'all', 'return' => 'https://evil.example/'])->assertRedirect('/monitoring');
    }
}
