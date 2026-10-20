<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\JobHealth;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class CommandsTest extends TestCase
{
    private function billableCustomer(): void
    {
        $this->actingAsOperator();
        $this->post('/services/save', [
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'custom_group_attrs' => '',
        ]);
        $this->post('/clients/save', [
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => 'alice', 'password' => 'pppoe-pass-1', 'verified' => '0',
            'account_status' => 'enabled', 'expiry_date' => date('Y-m-d', strtotime('+30 days')), 'service_group' => 'home50',
            'framed_ip_address' => '', 'framed_pool' => '', 'nas_restriction_type' => 'all', 'nas_restriction_value' => '', 'language' => 'en',
            '_active_tab' => 'account',
        ])->assertSessionHas('flash.type', 'success');
    }

    public function test_generate_billing_is_idempotent_and_recorded_as_a_job(): void
    {
        $this->billableCustomer();
        $this->artisan('frweb:generate-billing')->expectsOutputToContain('"created":1')->assertExitCode(0);
        $this->artisan('frweb:generate-billing', ['--username' => 'alice'])->expectsOutputToContain('"created":0')->assertExitCode(0);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM billing_invoices'));
        $this->assertSame('billing', Db::value('SELECT job_name FROM job_runs ORDER BY id DESC LIMIT 1'));
        $this->artisan('frweb:job-status')->expectsOutputToContain('"job_name": "billing"')->assertExitCode(0);
    }

    public function test_workers_run_against_an_empty_queue(): void
    {
        $this->artisan('frweb:sync-expired')->expectsOutputToContain('"reconciled"')->assertExitCode(0);
        $this->artisan('frweb:process-customer-notifications')->assertExitCode(0);
        $this->artisan('frweb:process-notifications')->assertExitCode(0);
        $this->artisan('frweb:check-schema')->expectsOutputToContain('"billing": []')->assertExitCode(0);
        $this->artisan('frweb:document-inventory')->expectsOutputToContain('missing_document_ids')->assertExitCode(0);
        $this->artisan('frweb:encrypt-secrets')->assertExitCode(0);
        // Every migration is already in the ledger, so a rerun applies nothing.
        $this->artisan('frweb:migrate')->assertExitCode(0);
        $this->assertSame(count(glob(base_path('database/sql/*.sql'))), (int) Db::value('SELECT COUNT(*) FROM schema_migrations'));
    }

    public function test_guarded_commands_refuse_without_their_safeguards(): void
    {
        $this->artisan('frweb:process-network-operations')
            ->expectsOutputToContain('Network worker stopped: RuntimeException')->assertExitCode(1);
        $this->artisan('frweb:mark-nas-applied', ['--id' => '1', '--revision' => '2'])
            ->expectsOutputToContain('Require --id, --revision, --evidence and --verified-activation')->assertExitCode(1);
        $this->artisan('frweb:bootstrap-operator')
            ->expectsOutputToContain('Use --create-first-superadmin')->assertExitCode(1);
        $this->artisan('frweb:seed')->expectsOutputToContain('Explicit --demo is required')->assertExitCode(1);
        $this->artisan('frweb:migrate', ['--baseline-through' => '010'])
            ->expectsOutputToContain('requires --confirm-schema-reviewed')->assertExitCode(1);
        $this->artisan('frweb:restore', ['file' => '/nonexistent.sql.gz'])->assertExitCode(1);
    }

    public function test_job_history_is_pruned_but_running_jobs_and_job_health_are_kept(): void
    {
        Db::exec('INSERT INTO job_runs (job_name, state, started_at, finished_at) VALUES
            ("customer-notifications", "succeeded", NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 40 DAY),
            ("billing", "running", NOW() - INTERVAL 40 DAY, NULL),
            ("customer-notifications", "succeeded", NOW() - INTERVAL 1 MINUTE, NOW() - INTERVAL 1 MINUTE)');
        $this->artisan('frweb:prune-job-runs')->expectsOutputToContain('"deleted":1')->assertExitCode(0);
        $this->assertSame(['running', 'succeeded'], array_column(Db::select('SELECT state FROM job_runs ORDER BY id'), 'state'));
        $this->assertSame('running', JobHealth::all()['customer-notifications']['state']);
    }

    public function test_paused_jobs_are_only_scheduled_when_enabled(): void
    {
        $due = function (): array {
            $schedule = $this->app->make(Schedule::class);
            return array_values(array_map(
                static fn (Event $event) => preg_replace('/^.*artisan.? /', '', $event->command),
                array_filter($schedule->events(), fn (Event $event) => $event->filtersPass($this->app) && str_contains($event->expression, '* * * *')),
            ));
        };
        $active = $due();
        $this->assertContains('frweb:process-customer-notifications', $active);
        $this->assertContains('frweb:poll-snmp', $active);
        $this->assertNotContains('frweb:sync-expired', $active);
        $this->assertNotContains('frweb:process-notifications', $active);

        config(['app.schedule_expiry_sync' => true]);
        $this->assertContains('frweb:sync-expired', $due());
    }
}
