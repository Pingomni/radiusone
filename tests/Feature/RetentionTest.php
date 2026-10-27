<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use Tests\TestCase;

final class RetentionTest extends TestCase
{
    private function accountingRow(string $id, ?string $stop, string $start, ?string $update = null): void
    {
        $this->insertRow('radacct', ['acctuniqueid' => $id, 'acctsessionid' => $id, 'username' => 'alice', 'nasipaddress' => '10.0.0.1',
            'acctstarttime' => $start, 'acctupdatetime' => $update, 'acctstoptime' => $stop]);
    }

    private function login(string $at): void
    {
        $this->insertRow('radpostauth', ['username' => 'alice', 'reply' => 'Access-Accept', 'authdate' => $at]);
    }

    private function ago(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    private function seedRecords(): void
    {
        $this->accountingRow('old-closed', $this->ago(100), $this->ago(101));
        $this->accountingRow('recent-closed', $this->ago(10), $this->ago(11));
        $this->accountingRow('live', null, $this->ago(200), $this->ago(0));       // long-running, still reporting
        $this->accountingRow('dead-open', null, $this->ago(150), $this->ago(120)); // never closed, silent since
        $this->login($this->ago(40));
        $this->login($this->ago(5));
    }

    public function test_keep_forever_is_the_default_and_deletes_nothing(): void
    {
        $this->actingAsOperator();
        $this->seedRecords();
        $this->get('/settings?tab=retention')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/Settings')
            ->where('retention.periods.sessions', 0)
            ->where('retention.periods.logins', 0)
            ->where('retention.options.sessions.365', '1 year')
            ->where('retention.stats.lastRun', null));
        $this->artisan('radiusone:prune-radius')->expectsOutputToContain('"sessions":0,"logins":0')->assertExitCode(0);
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM radacct'));
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM radpostauth'));
    }

    public function test_chosen_periods_delete_old_records_but_never_live_sessions(): void
    {
        $this->actingAsOperator();
        $this->seedRecords();
        $this->post('/settings/retention', ['sessions' => '90', 'logins' => '30'])
            ->assertRedirect('/settings?tab=retention')->assertSessionHas('flash.type', 'success');
        $audit = Db::row('SELECT before_json, after_json FROM audit_logs WHERE entity_type="setting" AND entity_id="retention"');
        $this->assertSame(['sessions' => 0, 'logins' => 0], json_decode((string) $audit['before_json'], true));
        $this->assertSame(['sessions' => 90, 'logins' => 30], json_decode((string) $audit['after_json'], true));

        $this->artisan('radiusone:prune-radius')->expectsOutputToContain('"sessions":2,"logins":1')->assertExitCode(0);
        $this->assertSame(['live', 'recent-closed'], array_column(Db::select('SELECT acctuniqueid FROM radacct ORDER BY acctuniqueid'), 'acctuniqueid'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM radpostauth'));
        $this->assertSame('prune', Db::value('SELECT action FROM audit_logs WHERE entity_type="radius_records" ORDER BY id DESC LIMIT 1'));

        $this->get('/settings?tab=retention')->assertInertia(fn ($page) => $page
            ->where('retention.periods.sessions', 90)
            ->where('retention.stats.lastRun.sessions', 2)
            ->where('retention.stats.lastRun.finished', true));
    }

    public function test_only_listed_periods_and_superadmin(): void
    {
        $user = $this->actingAsOperator();
        $this->post('/settings/retention', ['sessions' => '7', 'logins' => '30'])->assertSessionHas('flash.message', 'Choose one of the listed retention periods.');
        $this->assertFalse((bool) Db::value('SELECT COUNT(*) FROM settings WHERE setting_key LIKE "retention_%"'));

        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time() - 3600]);
        $this->post('/settings/retention', ['sessions' => '365', 'logins' => '90'])->assertSessionHas('flash.type', 'success');
        $this->assertTrue((bool) Db::value('SELECT COUNT(*) FROM settings WHERE setting_key LIKE "retention_%"'));

        $this->actingAsOperator('admin');
        $this->get('/settings?tab=retention')->assertForbidden();
        $this->post('/settings/retention', ['sessions' => '365', 'logins' => '90'])->assertForbidden();
    }
}
