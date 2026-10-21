<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\SubscriberPolicy;
use App\Support\UsageCaps;
use Tests\TestCase;

final class UsageCapsTest extends TestCase
{
    private const GB = 1073741824;

    private function plan(string $group, array $fields = []): void
    {
        $this->post('/services/save', array_merge([
            'id' => '', 'name' => ucfirst($group), 'group_name' => $group, 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'custom_group_attrs' => '',
            'total_quota' => '', 'cap_action' => 'notify', 'cap_service_group' => '',
        ], $fields))->assertSessionHas('flash.type', 'success');
    }

    private function client(string $username, string $plan): int
    {
        $this->post('/clients/save', [
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => $username, 'password' => 'pppoe-pass-1', 'verified' => '0',
            'account_status' => 'enabled', 'expiry_date' => date('Y-m-d', strtotime('+20 days')), 'service_group' => $plan,
            'framed_ip_address' => '', 'framed_pool' => '', 'email_alert' => true, 'sms_alert' => false, 'access_profile_id' => '',
            'dhcp_client_id' => '', 'circuit_id' => '', 'mac_address' => '', 'mac_lock' => false, 'nas_restriction_type' => 'all',
            'nas_restriction_value' => '', 'language' => 'en', 'first_name' => 'Alice', 'last_name' => 'Doe', 'company_name' => '',
            'vat_tax_id' => '', 'phone' => '+355600000', 'cell_phone' => '', 'email' => 'alice@example.test', 'address' => '', 'city' => 'Tirana',
            'state' => '', 'zip' => '', 'country' => 'AL', 'gps' => '', 'contract_id' => '', 'contract_valid_until' => '', 'notes' => '', '_active_tab' => 'account',
        ])->assertSessionHas('flash.type', 'success');
        return (int) Db::value('SELECT id FROM customer_profiles WHERE username=?', [$username]);
    }

    private function day(string $username, string $day): array
    {
        $row = Db::row('SELECT down, up FROM usage_daily WHERE username=? AND day=?', [$username, $day]);
        return $row ? [(int) $row['down'], (int) $row['up']] : [0, 0];
    }

    public function test_collector_adds_counter_growth_to_the_day_it_happened(): void
    {
        $now = time();
        $today = date('Y-m-d', $now);
        $old = date('Y-m-d', $now - 3 * 86400);
        $this->insertRow('radacct', ['username' => 'alice', 'acctstarttime' => "$old 08:00:00", 'acctupdatetime' => "$old 09:00:00", 'acctstoptime' => "$old 09:00:00", 'acctoutputoctets' => 700, 'acctinputoctets' => 70]);
        $open = $this->insertRow('radacct', ['username' => 'alice', 'acctstarttime' => date('Y-m-d H:i:s', $now - 600), 'acctupdatetime' => date('Y-m-d H:i:s', $now - 60), 'acctoutputoctets' => 1000, 'acctinputoctets' => 100]);

        // First run: the old closed session lands on its day, the open one counts in full today.
        $this->assertTrue(UsageCaps::collect($now)['backfilled']);
        $this->assertSame([700, 70], $this->day('alice', $old));
        $this->assertSame([1000, 100], $this->day('alice', $today));

        Db::exec('UPDATE radacct SET acctoutputoctets=1500, acctinputoctets=150 WHERE radacctid=?', [$open]);
        UsageCaps::collect($now + 300);
        $this->assertSame([1500, 150], $this->day('alice', $today));

        // A counter that went down (NAS restart reusing the session) counts from zero again.
        Db::exec('UPDATE radacct SET acctoutputoctets=200, acctinputoctets=20 WHERE radacctid=?', [$open]);
        UsageCaps::collect($now + 600);
        $this->assertSame([1700, 170], $this->day('alice', $today));

        // The session closes with a final count; running again adds nothing more.
        Db::exec('UPDATE radacct SET acctoutputoctets=300, acctinputoctets=30, acctstoptime=acctupdatetime WHERE radacctid=?', [$open]);
        UsageCaps::collect($now + 900);
        UsageCaps::collect($now + 1200);
        $this->assertSame([1800, 180], $this->day('alice', $today));
        $this->assertSame([700, 70], $this->day('alice', $old));
    }

    public function test_cycle_follows_the_expiry_date(): void
    {
        $this->assertSame(['2026-09-30', '2026-10-29'], UsageCaps::cycle('2026-10-29', 'monthly', '2026-10-21'));
        // Prepaid months ahead: the cycle that holds today.
        $this->assertSame(['2026-10-16', '2026-11-15'], UsageCaps::cycle('2027-01-15', 'monthly', '2026-10-21'));
        // Month ends are clamped (Feb has no 31st).
        $this->assertSame(['2026-03-01', '2026-03-31'], UsageCaps::cycle('2026-03-31', 'monthly', '2026-03-10'));
        // No expiry date: the calendar month.
        $this->assertSame(['2026-10-01', '2026-10-31'], UsageCaps::cycle(null, 'monthly', '2026-10-21'));
    }

    public function test_cap_warns_then_slows_down_until_released(): void
    {
        $this->actingAsOperator();
        $this->plan('slow2m', ['download_speed' => '2', 'upload_speed' => '1']);
        $this->post('/services/save', ['id' => '', 'name' => 'Capped', 'group_name' => 'capped', 'total_quota' => '1', 'cap_action' => 'throttle', 'cap_service_group' => 'capped'])
            ->assertSessionHas('flash.type', 'danger');
        $this->plan('capped', ['total_quota' => '1', 'cap_action' => 'throttle', 'cap_service_group' => 'slow2m']);
        $this->get('/services')->assertInertia(fn ($page) => $page->where('services.0.cap', '1 GB, then Slow2m'));
        $id = $this->client('alice', 'capped');
        $today = (string) Db::value('SELECT CURDATE()');
        Db::exec('INSERT INTO usage_daily (username, day, down, up) VALUES ("alice", ?, ?, 0)', [$today, (int) (0.85 * self::GB)]);

        $result = UsageCaps::caps($today);
        $this->assertSame(1, $result['warned']);
        $this->assertSame(0, $result['capped']);
        $cycle = Db::row('SELECT * FROM usage_cycles WHERE customer_id=?', [$id]);
        $this->assertSame(1, (int) $cycle['warned']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notification_events WHERE event_key=?', ['usage_warning:' . $id . ':' . $cycle['cycle_start']]));

        Db::exec('UPDATE usage_daily SET up = ? WHERE username="alice"', [(int) (0.2 * self::GB)]);
        $result = UsageCaps::caps($today);
        $this->assertSame(['warned' => 0, 'reached' => 1, 'capped' => 1], array_intersect_key($result, ['warned' => 1, 'reached' => 1, 'capped' => 1]));
        $this->assertSame(1, (int) Db::value('SELECT dirty FROM subscriptions WHERE customer_id=?', [$id]));
        // A second run sends nothing again in the same cycle.
        $this->assertSame(0, UsageCaps::caps($today)['reached']);

        SubscriberPolicy::due();
        $this->assertSame('slow2m', Db::value('SELECT groupname FROM radusergroup WHERE username="alice"'));
        $this->get('/clients/alice/edit')->assertInertia(fn ($page) => $page->where('client.usage.cycle.capped', true)->where('client.usage.cycle.slowPlan', 'Slow2m'));

        // The plan switches to notify only: the slow-down is lifted.
        $planId = (int) Db::value('SELECT id FROM service_metadata WHERE group_name="capped"');
        $this->plan('capped', ['id' => $planId, 'total_quota' => '1', 'cap_action' => 'notify']);
        $this->assertSame(1, UsageCaps::caps($today)['released']);
        SubscriberPolicy::due();
        $this->assertSame('capped', Db::value('SELECT groupname FROM radusergroup WHERE username="alice"'));

        // The slow-down plan of a cap cannot be disabled while a throttling plan points at it.
        $this->plan('capped', ['id' => $planId, 'total_quota' => '1', 'cap_action' => 'throttle', 'cap_service_group' => 'slow2m']);
        $slowId = (int) Db::value('SELECT id FROM service_metadata WHERE group_name="slow2m"');
        $this->post('/services/save', ['id' => $slowId, 'name' => 'Slow2m', 'group_name' => 'slow2m', 'enabled' => false, 'download_speed' => '2', 'upload_speed' => '1'])
            ->assertSessionHas('flash.type', 'danger');
    }

    public function test_usage_job_runs_from_the_scheduler_command(): void
    {
        $this->insertRow('radacct', ['username' => 'bob', 'acctstarttime' => date('Y-m-d H:i:s'), 'acctupdatetime' => date('Y-m-d H:i:s'), 'acctoutputoctets' => 5, 'acctinputoctets' => 1]);
        $this->artisan('frweb:collect-usage')->assertSuccessful();
        $this->assertSame('succeeded', Db::value('SELECT state FROM job_runs WHERE job_name="usage" ORDER BY id DESC LIMIT 1'));
        $this->assertSame([5, 1], $this->day('bob', date('Y-m-d')));
    }
}
