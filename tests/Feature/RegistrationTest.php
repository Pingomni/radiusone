<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Activation;
use App\Support\License;
use App\Support\Registration;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Registering the server: RadiusOne Community's free key, the 30-day grace, and the web console's lock afterwards. */
final class RegistrationTest extends TestCase
{
    private static function communityKey(string $expires): string
    {
        return self::licenceKey([], $expires, 'Small ISP', clients: 150, edition: 'community');
    }

    private static function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function test_a_community_key_registers_and_turns_on_no_pro_feature(): void
    {
        $licence = License::save(self::communityKey(date('Y-m-d', strtotime('+1 year'))));
        $this->assertSame('active', $licence['state']);
        $this->assertSame('community', $licence['edition']);
        $this->assertSame([], $licence['features']);
        $this->assertSame([], array_filter(License::features()));
        $this->assertSame(150, License::clientLimit());
        $this->assertSame('registered', Registration::status()['state']);
        // Even a Community key claiming more cannot turn anything on.
        $this->assertSame([], License::describe(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year')), clients: 0, edition: 'community'))['features']);
        // Pro keys name their edition too; keys from before it are Pro.
        $this->assertSame('pro', License::describe(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year')), edition: 'pro'))['edition']);
        $this->assertSame('pro', License::describe(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year'))))['edition']);

        $this->actingAsOperator();
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('registration', null)->where('pro.billing', false));
        $this->get('/billing')->assertForbidden()->assertInertia(fn ($page) => $page->component('Pro'));
        $this->get('/settings?tab=license')->assertInertia(fn ($page) => $page->where('license.edition', 'community')
            ->where('license.state', 'active')->where('license.clientLimit', 150)->where('license.registration.state', 'registered'));
        $this->assertNull(Settings::value(Registration::GRACE_KEY, '') ?: null, 'a registered server never starts the grace');

        // The 151st client is refused, as in Community without a key.
        for ($i = 1; $i <= 150; $i++) {
            $this->insertRow('customer_profiles', ['username' => 'c' . $i, 'account_status' => 'active']);
        }
        try {
            License::assertRoomForClient();
            $this->fail('The 151st client was allowed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Community covers up to 150 clients', $e->getMessage());
        }
    }

    public function test_a_community_key_registers_with_the_account_portal(): void
    {
        $machine = (string) tempnam(sys_get_temp_dir(), 'mid');
        file_put_contents($machine, "0123456789abcdef0123456789abcdef\n");
        config(['services.pingomni_account.url' => 'https://account.test', 'services.pingomni_account.machine_id_file' => $machine]);
        License::save(self::communityKey(date('Y-m-d', strtotime('+1 year'))));
        Http::fake(['account.test/api/v1/activate' => Http::response(['token' => str_repeat('t', 64), 'lease_until' => '2026-12-01'])]);

        $this->assertSame('registered', Activation::run()['status']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://account.test/api/v1/activate');
        @unlink($machine);
    }

    public function test_the_grace_starts_once_and_shows_a_banner(): void
    {
        License::save('');
        $this->actingAsOperator();

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('registration.reason', 'none')
            ->where('registration.days_left', 30)->where('registration.url', Registration::URL));
        $this->assertSame(date('Y-m-d'), Settings::value(Registration::GRACE_KEY));

        // Counted from the first time, not restarted.
        Settings::save(Registration::GRACE_KEY, self::daysAgo(25));
        foreach (['/clients', '/services', '/nas', '/settings'] as $page) {
            $this->get($page)->assertOk()->assertInertia(fn ($p) => $p->where('registration.days_left', 5));
        }
        $this->post('/settings/branding/name', ['company_name' => 'Net ISP'])->assertRedirect('/settings?tab=branding');
    }

    public function test_after_the_grace_only_the_registration_page_and_exempt_routes_open(): void
    {
        License::save('');
        Settings::save(Registration::GRACE_KEY, self::daysAgo(31));
        $operator = $this->createOperator('superadmin', ['password' => 'correct horse battery']);

        // Signing in still works, with its two-step setup, and then leads to the registration page.
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/uploads/branding/missing.png')->assertNotFound();
        $this->post('/login', ['username' => $operator['username'], 'password' => 'correct horse battery'])->assertRedirect('/two-factor/setup');
        $this->get('/two-factor/setup')->assertOk();
        $this->get('/')->assertRedirect('/login');

        $this->actingAsOperator();
        foreach (['/', '/dashboard', '/clients', '/nas', '/settings', '/settings?tab=overview', '/profile', '/reports'] as $page) {
            $this->get($page)->assertRedirect('/register');
        }
        $this->post('/clients/save', [])->assertStatus(303)->assertRedirect('/register');
        $this->getJson('/nas/health')->assertForbidden()->assertJson(['error' => 'registration']);

        $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('Register')
            ->where('reason', 'none')->where('url', Registration::URL)->where('canSave', true));
        $this->get('/settings?tab=license')->assertOk()->assertInertia(fn ($page) => $page->component('settings/Settings')
            ->where('license.registration.state', 'locked')->where('registration', null));
        $this->get('/reauth')->assertOk();
        $this->get('/two-factor')->assertRedirect('/login');

        // A key that is not valid is refused on the registration page itself.
        $this->post('/settings/license', ['license_key' => 'RADIUSONE1.e30.AAAA', 'from' => 'register'])->assertRedirect('/register');

        // Machines never pass through the web console: the scheduler's commands still run.
        config(['services.pingomni_account.url' => '']);
        $this->artisan('license:check-in')->assertExitCode(0);
        $this->post('/logout')->assertRedirect();
    }

    public function test_saving_a_community_key_unlocks_the_console_at_once(): void
    {
        License::save('');
        Settings::save(Registration::GRACE_KEY, self::daysAgo(45));
        $this->actingAsOperator();
        $this->get('/clients')->assertRedirect('/register');

        $this->post('/settings/license', ['license_key' => self::communityKey(date('Y-m-d', strtotime('+1 year'))), 'from' => 'register'])
            ->assertRedirect('/settings?tab=license');
        $this->assertStringContainsString('registered: RadiusOne Community', (string) session('flash.message'));
        $this->get('/clients')->assertOk()->assertInertia(fn ($page) => $page->where('registration', null));
        $this->get('/register')->assertRedirect('/');

        // The grace is not given again: removing the key locks the console at once.
        $this->post('/settings/license', ['license_key' => ''])->assertRedirect('/settings?tab=license');
        $this->get('/clients')->assertRedirect('/register');
    }

    public function test_an_expired_key_keeps_30_days_then_locks(): void
    {
        $this->actingAsOperator();

        // Expired ten days ago: still registered, with a banner, and its grace is the days after the expiry.
        License::save(self::communityKey(self::daysAgo(10)));
        $this->get('/clients')->assertOk()->assertInertia(fn ($page) => $page->where('registration.reason', 'expired')->where('registration.days_left', 20));
        $this->assertSame(self::daysAgo(10), Settings::value(Registration::GRACE_KEY));

        // More than 30 days after the expiry: locked, and asked to renew.
        License::save(self::communityKey(self::daysAgo(40)));
        Settings::save(Registration::GRACE_KEY, self::daysAgo(40));
        $this->get('/clients')->assertRedirect('/register');
        $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('Register')->where('reason', 'expired')->where('expires', self::daysAgo(40)));

        // A renewed key opens the console again.
        License::save(self::communityKey(date('Y-m-d', strtotime('+1 year'))));
        $this->get('/clients')->assertOk();
    }

    public function test_a_server_upgraded_with_a_long_expired_key_gets_the_grace(): void
    {
        License::save(self::licenceKey(['all'], self::daysAgo(90)));
        $this->actingAsOperator();
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('registration.reason', 'expired')->where('registration.days_left', 30));
    }
}
