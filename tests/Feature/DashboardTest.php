<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\MicroCache;
use App\Support\NavCounts;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    public function test_says_everything_is_working_when_nothing_needs_a_look(): void
    {
        $this->actingAsOperator();

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('state.headline', 'Everything is working.')
            ->where('attention', [])
            ->has('kpis', 6)
            ->where('kpis.0.label', 'Online now'));
    }

    public function test_weak_onus_lead_the_headline_and_link_to_the_filtered_list(): void
    {
        $this->actingAsOperator();
        $olt = $this->insertRow('olts', ['name' => 'OLT-Center', 'model' => 'MA5800', 'host' => '10.0.9.2', 'enabled' => 1, 'rx_warning' => -27, 'rx_critical' => -30, 'last_sync_ok' => 1]);
        foreach ([-19.5, -27.6, -28.1, -31.0] as $i => $rx) {
            $this->insertRow('olt_onus', ['olt_id' => $olt, 'frame' => 0, 'slot' => 1, 'port' => 3, 'ont_id' => $i, 'sn' => 'HWTC000' . $i, 'status' => 'online', 'rx_dbm' => $rx]);
        }

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state.headline', '3 ONUs have a weak signal.')
            ->where('attention.0.tone', 'danger')
            ->where('attention.0.title', '3 ONUs have a weak signal on OLT-Center')
            ->where('attention.0.detail', '3 of them on port 0/1/3.')
            ->where('attention.0.action.href', '/onus?olt=' . $olt . '&status=weak'));
    }

    public function test_the_fibre_figures_count_onus_by_state_and_link_to_their_lists(): void
    {
        $this->actingAsOperator();
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('fibre', null)->where('kpis.4.label', 'Data today')->where('kpis.4.href', '/reports'));
        $olt = $this->insertRow('olts', ['name' => 'OLT-Center', 'model' => 'MA5800', 'host' => '10.0.9.2', 'enabled' => 1, 'rx_warning' => -27, 'rx_critical' => -30, 'last_sync_ok' => 1,
            'inventory' => json_encode(['waiting' => [['sn' => 'HWTCAAAA0001', 'position' => '0/1/0', 'model' => 'EG8145V5']]])]);
        foreach ([['online', -19.5, ''], ['online', -28.1, ''], ['online', -31.0, ''], ['offline', null, 'Power off (dying gasp)'], ['offline', null, 'LOS'], ['offline', null, '']] as $i => [$status, $rx, $cause]) {
            $this->insertRow('olt_onus', ['olt_id' => $olt, 'frame' => 0, 'slot' => 1, 'port' => 3, 'ont_id' => $i, 'sn' => 'HWTC000' . $i, 'status' => $status, 'rx_dbm' => $rx, 'down_cause' => $cause]);
        }
        $figures = fn (AssertableInertia $page) => $page
            // One strip of six: two for clients, four for ONUs, each with an icon and a list behind it.
            ->has('kpis', 6)
            ->where('kpis.0.label', 'Clients online')->where('kpis.0.icon', 'clients')->where('kpis.0.href', '/clients?status=online')
            ->where('kpis.1.label', 'Expired')->where('kpis.1.href', '/clients?status=expired')
            ->where('kpis.2', ['label' => 'ONUs waiting', 'value' => '1', 'icon' => 'plus', 'tone' => 'warning', 'href' => '/onus?status=waiting', 'note' => 'Not added yet'])
            ->where('kpis.3.value', '3')->where('kpis.3.note', 'of 6')->where('kpis.3.href', '/onus?status=online')
            ->where('kpis.4.value', '3')->where('kpis.4.note', 'Power 1 · LOS 1 · Other 1')->where('kpis.4.href', '/onus?status=offline')
            ->where('kpis.5.value', '2')->where('kpis.5.note', 'Warning 1 · Critical 1')->where('kpis.5.tone', 'danger')->where('kpis.5.href', '/onus?status=weak')
            ->where('fibre.olts.0.name', 'OLT-Center')->where('fibre.olts.0.online', 3)->where('fibre.olts.0.waiting', 1)->where('fibre.olts.0.status', 'Reading')
            ->where('fibre.chart.url', '/olts/' . $olt . '/graph?chart=onus&range=24h');
        $this->get('/')->assertOk()->assertInertia($figures);
        $this->getJson('/fragments/dashboard-fibre')->assertOk()->assertJsonPath('olts.0.online', 3);
        $this->getJson('/fragments/dashboard-kpis')->assertOk()->assertJsonPath('3.value', '3');
        // The list the figures open: every ONU on every OLT, with the same counts.
        $other = $this->insertRow('olts', ['name' => 'OLT-North', 'model' => 'MA5800', 'host' => '10.0.9.3', 'enabled' => 1, 'rx_warning' => -27, 'rx_critical' => -30, 'last_sync_ok' => 1]);
        $far = $this->insertRow('olt_onus', ['olt_id' => $other, 'frame' => 0, 'slot' => 2, 'port' => 0, 'ont_id' => 0, 'sn' => 'HWTCNORTH001', 'description' => 'North shop', 'status' => 'online', 'rx_dbm' => -20]);
        $this->get('/onus')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('olts/Onus')
            ->where('counts', ['total' => 7, 'online' => 4, 'offline' => 3, 'weak' => 2, 'waiting' => 1])
            ->where('result.total', 7)->where('result.rows.0.olt', 'OLT-Center')->where('result.rows.6.olt', 'OLT-North')->where('result.rows.6.description', 'North shop'));
        $this->get('/onus?status=offline')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 3));
        $this->get('/onus?status=weak')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 2));
        $this->get('/onus?olt=' . $other)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 1)->where('counts.total', 1)->where('counts.waiting', 0));
        $this->get('/onus?q=north')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 1)->where('result.rows.0.sn', 'HWTCNORTH001'));
        $this->get('/onus?status=waiting')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('waiting.0.sn', 'HWTCAAAA0001')->where('waiting.0.olt', 'OLT-Center'));
        // From one ONU to the next without the list: the last ONU of an OLT leads on to the first of the next OLT.
        $last = (int) \App\Support\Db::value('SELECT id FROM olt_onus WHERE olt_id=? ORDER BY ont_id DESC LIMIT 1', [$olt]);
        $this->get('/olts/' . $olt . '/onus/' . $last)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('neighbours.next.href', '/olts/' . $other . '/onus/' . $far)->where('neighbours.next.name', 'North shop')
            ->where('neighbours.previous.position', '0/1/3:4'));
        // The top search finds ONUs, OLTs and routers too.
        $this->getJson('/search?q=north')->assertOk()->assertJsonPath('onus.0.href', '/olts/' . $other . '/onus/' . $far)->assertJsonPath('onus.0.where', 'OLT-North 0/2/0:0')
            ->assertJsonPath('devices.0.name', 'OLT-North');
    }

    public function test_menu_overview_and_olt_page_count_weak_onus_the_same_way(): void
    {
        $this->actingAsOperator();
        $olt = $this->insertRow('olts', ['name' => 'OLT-Center', 'model' => 'MA5800', 'host' => '10.0.9.2', 'enabled' => 1, 'rx_warning' => -27, 'rx_critical' => -30, 'last_sync_ok' => 1]);
        $onus = [
            ['online', -20.0, -21.0],  // good
            ['online', -28.0, -20.0],  // weak at the ONU
            ['online', -20.0, -28.5],  // weak at the OLT
            ['offline', -31.0, -31.0], // offline: an old reading, not counted
        ];
        foreach ($onus as $i => [$status, $rx, $oltRx]) {
            $this->insertRow('olt_onus', ['olt_id' => $olt, 'frame' => 0, 'slot' => 1, 'port' => 2, 'ont_id' => $i, 'sn' => 'HWTC100' . $i, 'status' => $status, 'rx_dbm' => $rx, 'olt_rx_dbm' => $oltRx]);
        }

        MicroCache::forget(NavCounts::CACHE_KEY);
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nav.weakOnus', 2)
            ->where('attention.0.title', '2 ONUs have a weak signal on OLT-Center'));
        $this->get('/olts/' . $olt)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('counts.weak', 2));
        $this->get('/olts/' . $olt . '?status=weak')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 2));
    }

    public function test_lists_clients_that_expire_this_week(): void
    {
        $this->actingAsOperator();
        $this->insertRow('customer_profiles', ['username' => 'klea.m', 'first_name' => 'Klea', 'last_name' => 'Muca', 'account_status' => 'active', 'enabled' => 1, 'expiry_date' => date('Y-m-d', strtotime('+1 day'))]);
        $this->insertRow('customer_profiles', ['username' => 'later', 'account_status' => 'active', 'enabled' => 1, 'expiry_date' => date('Y-m-d', strtotime('+20 days'))]);

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expiring.total', 1)
            ->where('expiring.rows.0.username', 'klea.m')
            ->where('expiring.rows.0.when', 'Tomorrow'));
    }
}
