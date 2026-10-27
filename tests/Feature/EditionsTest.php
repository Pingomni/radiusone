<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\License;
use App\Support\Settings;
use Tests\TestCase;

/** RadiusOne Community and Pro: what works without a licence, with one, once it has expired, and the License tab. */
final class EditionsTest extends TestCase
{
    public function test_community_keeps_the_core_and_explains_the_pro_pages(): void
    {
        License::save('');
        $this->actingAsOperator();

        foreach (['/', '/clients', '/services', '/nas', '/monitoring', '/monitoring/server', '/reports', '/settings?tab=backups'] as $page) {
            $this->get($page)->assertOk();
        }
        $this->get('/billing')->assertForbidden()->assertInertia(fn ($page) => $page->component('Pro')->where('feature', 'billing'));
        $this->get('/nas/1/monitor')->assertForbidden()->assertInertia(fn ($page) => $page->component('Pro')->where('feature', 'monitoring'));
        $this->getJson('/nas/1/monitor/live')->assertForbidden()->assertJson(['error' => 'pro']);
        $this->get('/')->assertInertia(fn ($page) => $page->where('pro.billing', false)->where('pro.branding', false));

        // Changes to Pro settings are refused; Community settings still save.
        $this->from('/settings?tab=notifications')->post('/settings/notifications/templates', [])->assertRedirect('/settings?tab=notifications');
        $this->assertStringContainsString('RadiusOne Pro', (string) session('flash.message'));
        $this->post('/settings/branding/logo/url', ['portal_logo_url' => 'https://example.com/logo.png'])->assertRedirect();
        $this->assertSame('', Settings::value('portal_logo_path', ''));
        $this->post('/settings/branding/name', ['company_name' => 'Net ISP'])->assertRedirect('/settings?tab=branding');
        $this->assertSame('Net ISP', Settings::value('company_name', ''));
    }

    public function test_a_custom_logo_shows_only_with_pro(): void
    {
        $this->actingAsOperator();
        Settings::save('portal_logo_path', 'https://example.com/logo.png');
        $this->get('/')->assertInertia(fn ($page) => $page->where('logo', 'https://example.com/logo.png'));
        License::save('');
        $this->get('/')->assertInertia(fn ($page) => $page->where('logo', null));
    }

    public function test_an_expired_licence_keeps_pro_readable_but_not_changeable(): void
    {
        License::save(self::licenceKey(['billing'], date('Y-m-d', strtotime('-1 day'))));
        $this->actingAsOperator();

        $this->get('/billing')->assertOk();
        $this->assertTrue(License::allows('billing'));
        $this->assertFalse(License::allows('billing', write: true));
        $this->post('/billing/generate')->assertRedirect();
        $this->assertStringContainsString('expired', (string) session('flash.message'));
        // Features the key never had stay off.
        $this->get('/nas/1/monitor')->assertForbidden();
    }

    public function test_the_license_tab_adds_replaces_and_removes_the_key(): void
    {
        License::save('');
        $this->actingAsOperator();
        $this->get('/settings?tab=license')->assertOk()->assertInertia(fn ($page) => $page->component('settings/Settings')->where('license.state', 'none'));

        // A key changed after signing is refused, and nothing is saved.
        $key = self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year')), 'Net ISP');
        [$prefix, $payload, $signature] = explode('.', $key);
        $forged = $prefix . '.' . rtrim(strtr(base64_encode('{"v":1,"id":"x","expires":"2099-01-01","features":["all"]}'), '+/', '-_'), '=') . '.' . $signature;
        $this->post('/settings/license', ['license_key' => $forged])->assertRedirect('/settings?tab=license');
        $this->assertSame('none', License::current()['state']);
        $this->post('/settings/license', ['license_key' => 'DNSONE1.' . $payload . '.' . $signature])->assertRedirect();
        $this->assertSame('none', License::current()['state']);

        // Pasted with line breaks, as from an email.
        $this->post('/settings/license', ['license_key' => chunk_split($key, 60)])->assertRedirect('/settings?tab=license');
        $this->get('/settings?tab=license')->assertInertia(fn ($page) => $page->where('license.state', 'active')->where('license.customer', 'Net ISP')->where('license.features.billing', true));
        $this->get('/billing')->assertOk();

        $this->post('/settings/license', ['license_key' => ''])->assertRedirect('/settings?tab=license');
        $this->assertSame('none', License::current()['state']);
        $this->get('/billing')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['entity_id' => 'license_key']);
    }

    public function test_a_key_for_some_features_turns_on_only_those(): void
    {
        License::save(self::licenceKey(['monitoring', 'not-a-feature'], date('Y-m-d', strtotime('+1 year'))));
        $this->assertSame(['monitoring' => true], array_filter(License::features()));
        $this->actingAsOperator();
        $this->get('/billing')->assertForbidden();
    }

    public function test_client_limits_by_edition(): void
    {
        $year = date('Y-m-d', strtotime('+1 year'));
        License::save('');
        $this->assertSame(150, License::clientLimit());
        License::save(self::licenceKey(['all'], $year));
        $this->assertSame(999, License::clientLimit(), 'a Pro key without a limit: 999');
        License::save(self::licenceKey(['all'], $year, clients: 0));
        $this->assertNull(License::clientLimit(), 'Licence 3: no limit');
        License::save(self::licenceKey(['all'], $year, clients: 20));
        $this->assertSame(150, License::clientLimit(), 'never fewer than Community');
        License::save(self::licenceKey(['all'], date('Y-m-d', strtotime('-1 day')), clients: 0));
        $this->assertSame(150, License::clientLimit(), 'expired: new clients held to Community');
    }

    public function test_community_refuses_the_151st_client_and_keeps_the_rest(): void
    {
        License::save('');
        $this->actingAsOperator();
        $this->post('/services/save', [
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'custom_group_attrs' => '',
        ])->assertSessionHas('flash.type', 'success');
        // 149 clients, and archived ones that do not count.
        for ($i = 1; $i <= 149; $i++) {
            $this->insertRow('customer_profiles', ['username' => 'c' . $i, 'account_status' => $i % 2 ? 'active' : 'suspended']);
        }
        $this->insertRow('customer_profiles', ['username' => 'gone', 'account_status' => 'archived']);
        $client = static fn (string $username) => [
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => $username, 'password' => 'pppoe-pass-1', 'verified' => '0',
            'account_status' => 'enabled', 'expiry_date' => date('Y-m-d', strtotime('+30 days')), 'service_group' => 'home50',
            'framed_ip_address' => '', 'framed_pool' => '', 'email_alert' => false, 'sms_alert' => false, 'access_profile_id' => '',
            'dhcp_client_id' => '', 'circuit_id' => '', 'mac_address' => '', 'mac_lock' => false, 'nas_restriction_type' => 'all',
            'nas_restriction_value' => '', 'language' => 'en', 'first_name' => 'New', 'last_name' => 'Client', 'company_name' => '',
            'vat_tax_id' => '', 'phone' => '', 'cell_phone' => '', 'email' => '', 'address' => '', 'city' => '',
            'state' => '', 'zip' => '', 'country' => 'AL', 'gps' => '', 'contract_id' => '', 'contract_valid_until' => '', 'notes' => '',
            '_active_tab' => 'account',
        ];

        $this->post('/clients/save', $client('number150'))->assertSessionHas('flash.type', 'success');
        $this->post('/clients/save', $client('number151'))->assertRedirect('/clients/create?tab=account');
        $this->assertStringContainsString('Community covers up to 150 clients', (string) session('flash.message'));
        $this->assertNull(Db::row('SELECT id FROM radcheck WHERE username="number151"'));
        // Existing clients can still be changed.
        $this->post('/clients/save', ['old_username' => 'number150', 'city' => 'Durres'] + $client('number150'))->assertSessionHas('flash.type', 'success');
        $this->get('/settings?tab=license')->assertInertia(fn ($page) => $page->where('license.clientCount', 150)->where('license.clientLimit', 150));

        // A Pro key makes room.
        License::save(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year'))));
        $this->post('/clients/save', $client('number151'))->assertSessionHas('flash.type', 'success');
    }
}
