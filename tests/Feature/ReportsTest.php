<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Audit;
use App\Support\Db;
use Tests\TestCase;

final class ReportsTest extends TestCase
{
    private function operation(string $state): int
    {
        // Foreign keys reach into billing and policy tables; only the joined columns matter here.
        Db::exec('SET FOREIGN_KEY_CHECKS=0');
        $this->insertRow('customer_profiles', ['id' => 7, 'username' => 'opcust']);
        $this->insertRow('subscriptions', ['id' => 3, 'customer_id' => 7, 'billing_account_id' => 1]);
        $id = $this->insertRow('network_operations', ['subscription_id' => 3, 'policy_revision' => 1, 'action' => 'apply', 'dedupe_key' => 'k1', 'state' => $state, 'attempts' => 1]);
        Db::exec('SET FOREIGN_KEY_CHECKS=1');
        return $id;
    }

    public function test_operations_list_and_retry(): void
    {
        $this->actingAsOperator();
        $id = $this->operation('failed');
        $this->get('/operations')->assertOk()->assertInertia(fn ($page) => $page
            ->component('operations/Index')
            ->has('metrics', 3)
            ->where('operations.0.username', 'opcust')
            ->where('operations.0.state.tone', 'danger')
            ->where('operations.0.retry', true));

        $this->post('/operations/' . $id . '/retry')->assertRedirect('/operations');
        $this->assertSame('uncertain', Db::value('SELECT state FROM network_operations WHERE id=?', [$id]));
    }

    public function test_operation_retry_needs_no_new_password_prompt(): void
    {
        $user = $this->createOperator();
        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time() - 3600]);
        $id = $this->operation('failed');
        $this->post('/operations/' . $id . '/retry')->assertRedirect();
        $this->assertNotSame('/reauth', parse_url((string) $this->post('/operations/' . $id . '/retry')->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_view_role_cannot_retry(): void
    {
        $this->actingAsOperator('view');
        $id = $this->operation('failed');
        $this->get('/operations')->assertForbidden();
        $this->post('/operations/' . $id . '/retry')->assertForbidden();
    }

    public function test_reports_range_and_sessions(): void
    {
        $this->actingAsOperator('view');
        Db::exec('INSERT INTO radacct (username, nasipaddress, framedipaddress, acctstarttime, acctupdatetime, acctinputoctets, acctoutputoctets, acctsessiontime) VALUES ("alice", "10.0.0.1", "100.64.0.5", NOW(), NOW(), 1024, 2048, 60)');
        Db::exec('INSERT INTO radacct (username, nasipaddress, acctstarttime, acctstoptime, acctinputoctets, acctoutputoctets, acctsessiontime) VALUES ("bob", "10.0.0.1", "2020-01-01 10:00:00", "2020-01-01 11:00:00", 1, 1, 3600)');

        $this->get('/reports')->assertOk()->assertInertia(fn ($page) => $page
            ->component('reports/Reports')
            ->has('metrics', 4)
            ->has('online', 1)
            ->where('online.0.state', 'fresh accounting')
            ->where('sessionMatches', 1)
            ->where('daily.0.total', '3 KB'));

        $this->get('/reports?from=2020-01-02&to=2020-01-01&username=bob')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.from', '2020-01-01')
            ->where('filters.to', '2020-01-02')
            ->where('sessions.0.username', 'bob')
            ->where('sessions.0.time', '01h 00m'));

        $this->get('/reports?from=not-a-date')->assertOk()->assertInertia(fn ($page) => $page->where('filters.to', date('Y-m-d')));
    }

    public function test_logs_filters(): void
    {
        $user = $this->actingAsOperator('view');
        Audit::log('update', 'client', 'alice');
        Audit::log('delete', 'service', '4');
        Db::exec('INSERT INTO radpostauth (username, pass, reply, authdate) VALUES ("alice", "", "Access-Reject", NOW()), ("bob", "", "Access-Accept", NOW())');

        $this->get('/logs')->assertOk()->assertInertia(fn ($page) => $page
            ->component('reports/Logs')
            ->where('auditMatches', 2)
            ->has('attempts', 2)
            ->where('metrics.3.tone', 'warning'));
        $this->get('/logs?q=service')->assertOk()->assertInertia(fn ($page) => $page->where('auditMatches', 1)->where('audit.0.entityType', 'service'));
        $this->get('/logs?reply=reject')->assertOk()->assertInertia(fn ($page) => $page->has('attempts', 1)->where('attempts.0.username', 'alice'));
        $this->get('/logs?auth_user=bo&reply=bogus')->assertOk()->assertInertia(fn ($page) => $page->where('filters.reply', '')->has('attempts', 1));
    }
}
