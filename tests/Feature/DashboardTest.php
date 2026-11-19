<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            ->where('attention.0.action.href', '/olts/' . $olt . '?status=weak'));
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
