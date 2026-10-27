<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Currency;
use App\Support\Db;
use App\Support\TimeZone;
use Tests\TestCase;

final class RegionalTest extends TestCase
{
    private function plan(string $group, string $currency = 'EUR'): void
    {
        $this->post('/services/save', [
            'id' => '', 'name' => ucfirst($group), 'group_name' => $group, 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => $currency, 'custom_group_attrs' => '',
        ])->assertSessionHas('flash.type', 'success');
    }

    private function invoiceRow(string $status, string $currency, float $total): void
    {
        Db::exec('SET FOREIGN_KEY_CHECKS=0');
        $this->insertRow('billing_invoices', ['invoice_number' => 'INV-' . $status . $currency, 'username' => 'alice', 'status' => $status, 'currency' => $currency,
            'subtotal' => $total, 'total_amount' => $total, 'balance_due' => $status === 'paid' ? 0 : $total, 'amount_paid' => $status === 'paid' ? $total : 0]);
        Db::exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_regional_tab_shows_time_zone_and_currency(): void
    {
        $this->actingAsOperator();
        $this->get('/settings?tab=regional')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'regional')
            ->where('regional.timezone', date_default_timezone_get())
            ->has('regional.timezones.Europe/Tirane')
            ->where('regional.timezoneReady', false)
            ->where('regional.currency', 'USD')
            ->where('regional.currencies', Currency::OPTIONS)
            ->where('currency', 'USD'));
    }

    public function test_the_system_currency_is_used_by_plans_pools_and_new_invoices(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/currency', ['currency' => 'ALL'])->assertRedirect('/settings?tab=regional')->assertSessionHas('flash.type', 'success');
        $this->assertSame('ALL', Currency::code());

        // Forms no longer choose a currency: a posted one is ignored.
        $this->plan('home50', 'USD');
        $this->assertSame('ALL', Db::value('SELECT currency FROM service_metadata WHERE group_name="home50"'));
        $this->post('/services/ip-pools/save', ['id' => '', 'name' => 'Office', 'pool_key' => '', 'ip_ranges' => '10.0.0.10-12', 'price' => '5', 'currency' => 'EUR', 'description' => '', 'enabled' => true]);
        $this->assertSame('ALL', Db::value('SELECT currency FROM static_ip_pools'));
        $this->get('/billing/invoices/create')->assertOk()->assertInertia(fn ($page) => $page->where('values.currency', 'ALL')->where('currency', 'ALL'));
    }

    public function test_changing_currency_relabels_prices_and_drafts_but_not_issued_invoices(): void
    {
        $this->actingAsOperator();
        Currency::change('USD');
        $this->plan('home50');
        $this->invoiceRow('draft', 'USD', 20);
        $this->invoiceRow('open', 'USD', 20);

        $this->post('/settings/currency', ['currency' => 'EUR'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, '1 service plans, 0 IP pools and 1 draft invoices') && str_contains($m, 'not converted'));
        $this->assertSame('EUR', Db::value('SELECT currency FROM service_metadata'));
        $this->assertSame(['draft' => 'EUR', 'open' => 'USD'], array_column(Db::select('SELECT status, currency FROM billing_invoices'), 'currency', 'status'));
        $this->assertSame('20.00', Db::value('SELECT price FROM service_metadata'));

        // Totals keep each currency apart instead of adding euros to dollars.
        $this->invoiceRow('overdue', 'EUR', 15);
        $this->get('/billing')->assertOk()->assertInertia(fn ($page) => $page->where('metrics.0.value', '15.00 EUR + 20.00 USD'));

        $this->get('/settings?tab=regional')->assertInertia(fn ($page) => $page->where('regional.issuedOther', 1));
    }

    public function test_only_euro_dollar_and_lek_and_only_for_superadmins(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/currency', ['currency' => 'GBP'])->assertSessionHas('flash.type', 'danger');
        $this->assertFalse((bool) Db::value('SELECT COUNT(*) FROM settings WHERE setting_key="billing_currency"'));

        $this->actingAsOperator('view');
        $this->post('/settings/currency', ['currency' => 'EUR'])->assertForbidden();
        $this->post('/settings/timezone', ['timezone' => 'Europe/Tirane'])->assertForbidden();
    }

    public function test_time_zone_change_is_handed_to_the_root_helper(): void
    {
        $this->actingAsOperator();
        $back = '/settings?tab=regional';
        $other = date_default_timezone_get() === 'Europe/Tirane' ? 'Europe/Rome' : 'Europe/Tirane';

        // Not installed yet.
        $this->post('/settings/timezone', ['timezone' => $other])->assertRedirect($back)
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'radiusone-postinstall'));

        // A stand-in helper records how it was called.
        $dir = (string) getenv('RADIUSONE_TEST_DIR');
        file_put_contents($dir . '/tz-helper', "#!/bin/sh\necho \"\$@\" > " . escapeshellarg($dir . '/tz-called') . "\necho scheduled\n");
        chmod($dir . '/tz-helper', 0755);
        TimeZone::$command = [$dir . '/tz-helper'];

        $this->post('/settings/timezone', ['timezone' => 'Mars/Olympus'])->assertSessionHas('flash.type', 'danger');
        $this->post('/settings/timezone', ['timezone' => date_default_timezone_get()])->assertSessionHas('flash.type', 'danger');
        $this->assertFileDoesNotExist($dir . '/tz-called');

        $this->post('/settings/timezone', ['timezone' => $other])->assertRedirect($back)->assertSessionHas('flash.type', 'success');
        $this->assertSame("schedule $other", trim((string) file_get_contents($dir . '/tz-called')));
        $this->assertTrue((bool) Db::value('SELECT COUNT(*) FROM audit_logs WHERE entity_id="timezone"'));

        // A refusal from the helper reaches the operator.
        file_put_contents($dir . '/tz-helper', "#!/bin/sh\necho 'A time zone change is already running.' >&2\nexit 3\n");
        $this->post('/settings/timezone', ['timezone' => $other])->assertSessionHas('flash.message', 'A time zone change is already running.');
    }
}
