<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class ClientListViewsTest extends TestCase
{
    private function clients(): void
    {
        $this->insertRow('customer_profiles', ['username' => 'soon', 'first_name' => 'Klea', 'last_name' => 'Muca', 'account_status' => 'active', 'enabled' => 1, 'expiry_date' => date('Y-m-d', strtotime('+2 days')), 'balance' => -24]);
        $this->insertRow('customer_profiles', ['username' => 'later', 'account_status' => 'active', 'enabled' => 1, 'expiry_date' => date('Y-m-d', strtotime('+20 days'))]);
        $this->insertRow('customer_profiles', ['username' => 'gone', 'account_status' => 'active', 'enabled' => 1, 'expiry_date' => date('Y-m-d', strtotime('-3 days'))]);
        $this->insertRow('customer_profiles', ['username' => 'paused', 'account_status' => 'suspended', 'enabled' => 1, 'expiry_date' => date('Y-m-d', strtotime('+2 days'))]);
    }

    public function test_view_tabs_count_and_the_expiring_view_lists_this_week_soonest_first(): void
    {
        $this->actingAsOperator();
        $this->clients();

        $this->get('/clients')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.all', 4)
            ->where('counts.expiring', 1)
            ->where('counts.expired', 1)
            ->where('counts.suspended', 1)
            ->where('counts.online', 0));

        $this->get('/clients?status=expiring')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('result.total', 1)
            ->where('result.rows.0.username', 'soon')
            ->where('result.rows.0.name', 'Klea Muca')
            ->where('result.rows.0.expiresIn', 2)
            ->where('result.rows.0.owes', true)
            ->where('result.rows.0.live', 'offline'));
    }

    public function test_the_side_panel_has_subscription_balance_and_connection(): void
    {
        $this->actingAsOperator();
        $this->clients();

        $this->getJson('/clients/soon/panel')->assertOk()
            ->assertJsonPath('name', 'Klea Muca')
            ->assertJsonPath('account.tone', 'good')
            ->assertJsonPath('expiry.days', 2)
            ->assertJsonPath('expiry.tone', 'warning')
            ->assertJsonPath('balance.owes', true)
            ->assertJsonPath('live', null)
            ->assertJsonPath('onu', null);
        $this->getJson('/clients/nobody/panel')->assertNotFound();
    }

    public function test_the_side_panel_needs_the_clients_permission(): void
    {
        $this->clients();
        $this->assertNotSame(200, $this->getJson('/clients/soon/panel')->status());
    }
}
