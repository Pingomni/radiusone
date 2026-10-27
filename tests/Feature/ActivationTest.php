<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Activation;
use App\Support\License;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The licence's server, registered with the PingOmni account portal (report only), against a fake portal. */
final class ActivationTest extends TestCase
{
    private string $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = tempnam(sys_get_temp_dir(), 'mid');
        file_put_contents($this->machine, "0123456789abcdef0123456789abcdef\n");
        config(['services.pingomni_account.url' => 'https://account.test', 'services.pingomni_account.machine_id_file' => $this->machine]);
        Settings::save(Activation::KEY, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->machine);
        parent::tearDown();
    }

    public function test_a_saved_key_registers_then_checks_in_twice_a_day(): void
    {
        License::save(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year')), clients: 999));
        Http::fake([
            'account.test/api/v1/activate' => Http::response(['token' => str_repeat('t', 64), 'lease_until' => '2026-12-01']),
            'account.test/api/v1/check-in' => Http::response(['lease_until' => '2026-12-02']),
        ]);

        $state = Activation::run();
        $this->assertSame('registered', $state['status']);
        $this->assertSame(str_repeat('t', 64), $state['token']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://account.test/api/v1/activate'
            && strlen($r['fingerprint']) === 64 && str_starts_with($r['key'], License::PREFIX . '.'));

        // Not due again for 12 hours: nothing is sent.
        Activation::run();
        Http::assertSentCount(1);

        // Once due, a check-in with the token.
        Settings::save(Activation::KEY, (string) json_encode([...Activation::state(), 'last_attempt' => date('c', time() - 13 * 3600)]));
        $state = Activation::run();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://account.test/api/v1/check-in' && $r['token'] === str_repeat('t', 64));
        $this->assertSame('2026-12-02', $state['lease_until']);
        $this->actingAsOperator();
        $this->get('/settings?tab=license')->assertInertia(fn ($page) => $page->where('license.activation.status', 'registered'));
    }

    public function test_an_updated_key_from_the_account_is_installed_once_verified(): void
    {
        $old = self::licenceKey(['all'], date('Y-m-d', strtotime('+10 days')), clients: 999);
        $new = self::licenceKey(['all'], date('Y-m-d', strtotime('+375 days')), clients: 4999);
        License::save($old);
        Http::fake(['account.test/api/v1/activate' => Http::response(['token' => str_repeat('t', 64), 'key' => $new])]);
        Activation::run();
        $this->assertSame($new, Settings::value('license_key'));
        $this->assertSame(4999, License::clientLimit());

        // A key not signed by PingOmni is never taken.
        Http::fake(['account.test/api/v1/*' => Http::response(['token' => str_repeat('t', 64), 'key' => 'RADIUSONE1.e30.AAAA'])]);
        Activation::run(force: true);
        $this->assertSame($new, Settings::value('license_key'));
    }

    public function test_refusals_and_outages_are_reported_never_enforced(): void
    {
        $key = self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year')));
        License::save($key);
        Http::fake(['account.test/*' => Http::response(['error' => 'in_use', 'message' => 'This licence is active on another server (radius2).'], 409)]);
        $state = Activation::run();
        $this->assertSame('in_use', $state['status']);
        $this->assertStringContainsString('radius2', $state['message']);
        $this->assertSame($key, Settings::value('license_key'));
        $this->assertSame('active', License::current()['state']);

        Http::fake(fn () => throw new ConnectionException('no route'));
        $this->assertSame('unreachable', Activation::run(force: true)['status']);
        $this->assertSame('active', License::current()['state']);
    }

    public function test_removing_the_key_releases_the_server(): void
    {
        License::save(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year'))));
        Http::fake([
            'account.test/api/v1/activate' => Http::response(['token' => str_repeat('t', 64)]),
            'account.test/api/v1/deactivate' => Http::response(['released' => true]),
        ]);
        Activation::run();
        License::save('');
        $this->assertSame([], Activation::run());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://account.test/api/v1/deactivate');
        $this->assertSame([], Activation::state());
    }

    public function test_off_sends_nothing(): void
    {
        config(['services.pingomni_account.url' => '']);
        License::save(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year'))));
        Http::fake();
        Activation::run();
        Http::assertNothingSent();
        $this->assertSame('off', Activation::public()['status']);
    }
}
