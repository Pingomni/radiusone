<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SearchTest extends TestCase
{
    private function fixtures(): void
    {
        $this->insertRow('customer_profiles', ['username' => 'klea.m', 'first_name' => 'Klea', 'last_name' => 'Muca', 'account_status' => 'active', 'enabled' => 1, 'framed_ip_address' => '100.64.14.9']);
        $this->insertRow('customer_profiles', ['username' => 'arben.k', 'first_name' => 'Arben', 'last_name' => 'Krasniqi', 'account_status' => 'active', 'enabled' => 1]);
        $this->insertRow('billing_invoices', ['invoice_number' => 'INV-2026-1184', 'username' => 'arben.k']);
    }

    public function test_finds_clients_by_name_and_ip_and_invoices_by_number(): void
    {
        $this->actingAsOperator();
        $this->fixtures();

        $this->getJson('/search?q=klea')->assertOk()->assertJsonPath('clients.0.username', 'klea.m')->assertJsonPath('clients.0.href', '/clients/klea.m/edit')->assertJsonCount(1, 'clients');
        $this->getJson('/search?q=100.64.14')->assertOk()->assertJsonPath('clients.0.username', 'klea.m');
        $this->getJson('/search?q=1184')->assertOk()->assertJsonPath('invoices.0.number', 'INV-2026-1184')->assertJsonCount(0, 'clients');
        $this->getJson('/search?q=k')->assertOk()->assertJsonCount(0, 'clients')->assertJsonCount(0, 'invoices');
    }

    public function test_shows_only_what_the_operator_may_see(): void
    {
        $this->actingAsOperator('support');
        $this->fixtures();

        $this->getJson('/search?q=1184')->assertOk()->assertJsonCount(0, 'invoices');
        $this->getJson('/search?q=arben')->assertOk()->assertJsonPath('clients.0.username', 'arben.k');
    }

    public function test_needs_a_signed_in_operator(): void
    {
        $this->fixtures();
        $this->assertNotSame(200, $this->getJson('/search?q=klea')->status());
    }
}
