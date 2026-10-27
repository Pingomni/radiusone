<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\SoftwareUpdate;
use Tests\TestCase;

final class SoftwareUpdateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = (string) getenv('FRWEB_TEST_DIR');
        @unlink($this->dir . '/update-args');
    }

    /** A stand-in for ops/frweb-update: answers "check" with $json and records the arguments it got. */
    private function helper(string $json, int $exit = 0): void
    {
        $script = "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->dir . '/update-args') . "\n"
            . "if [ \"\$1\" = check ]; then cat <<'JSON'\n" . $json . "\nJSON\nfi\nexit " . $exit . "\n";
        file_put_contents($this->dir . '/update-helper', $script);
        chmod($this->dir . '/update-helper', 0755);
        SoftwareUpdate::$command = [$this->dir . '/update-helper'];
    }

    private function newer(string $problem = ''): string
    {
        return json_encode(['ok' => 1, 'current' => '2.0.1', 'latest' => '9.1.0', 'newer' => 1, 'tag' => 'v9.1.0', 'notes' => "## 9.1.0\n- New things", 'problem' => $problem]);
    }

    public function test_the_update_addresses_opened_as_pages_go_to_settings(): void
    {
        $this->actingAsOperator();
        // A reload, Back or a reopened tab after the update was started: no 405, just the Settings page.
        $this->get('/settings/update/start')->assertRedirect('/settings');
        $this->get('/settings/update/check')->assertRedirect('/settings');
        // Any other button-only address opened as a page gets the portal's own error page.
        $this->get('/settings/license')->assertStatus(405)->assertInertia(fn ($page) => $page->where('title', 'Not a page'));
    }

    public function test_without_the_helper_the_page_says_how_to_enable_updates(): void
    {
        $this->actingAsOperator();
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('update.current', (string) config('app.version'))
            ->where('update.ready', false)
            ->where('update.running', false)
            ->where('update.check', null));
        $this->post('/settings/update/check')->assertRedirect('/settings')
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'apply-service-control.sh'));
        // Other tabs do not build it.
        $this->get('/settings?tab=branding')->assertInertia(fn ($page) => $page->where('update', null));
    }

    public function test_check_then_update_to_the_newest_release(): void
    {
        $this->actingAsOperator();
        $this->helper($this->newer());
        $this->post('/settings/update/check')->assertSessionHas('flash.message', 'Version 9.1.0 is available.');
        $this->get('/settings')->assertInertia(fn ($page) => $page
            ->where('update.ready', true)
            ->where('update.check.newer', true)
            ->where('update.check.latest', '9.1.0')
            ->where('update.check.notes', "## 9.1.0\n- New things"));

        $this->post('/settings/update/start')->assertRedirect('/settings')
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Update to 9.1.0 started'));
        $this->assertSame("check\nschedule v9.1.0\n", file_get_contents($this->dir . '/update-args'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="update" AND entity_type="software" AND entity_id="9.1.0"'));
    }

    public function test_up_to_date_and_failed_checks(): void
    {
        $this->actingAsOperator();
        $this->helper(json_encode(['ok' => 1, 'current' => '2.0.1', 'latest' => '2.0.1', 'newer' => 0]));
        $this->post('/settings/update/check')->assertSessionHas('flash.message', 'RadiusOne is up to date (2.0.1).');
        // Nothing newer: nothing to start.
        $this->post('/settings/update/start')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Check for updates first'));
        $this->assertSame("check\n", file_get_contents($this->dir . '/update-args'));

        $this->helper(json_encode(['ok' => 0, 'current' => '2.0.1', 'error' => 'Could not reach the update server: Permission denied (publickey).']));
        $this->post('/settings/update/check')->assertSessionHas('flash.type', 'danger')
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Permission denied (publickey)'));
    }

    public function test_a_server_that_cannot_fast_forward_is_not_updated(): void
    {
        $this->actingAsOperator();
        $this->helper($this->newer('Files in the app folder were changed by hand, so it cannot update automatically.'));
        $this->post('/settings/update/check');
        $this->post('/settings/update/start')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'changed by hand'));
        $this->assertStringNotContainsString('schedule', (string) file_get_contents($this->dir . '/update-args'));
    }

    public function test_progress_is_readable_during_maintenance_and_needs_settings_access(): void
    {
        $this->actingAsOperator();
        file_put_contents($this->dir . '/update-status.json', json_encode(['state' => 'running', 'step' => 'updating the database', 'message' => 'Updating the database.', 'from' => '2.0.1', 'to' => '9.1.0', 'at' => '2026-10-20 23:00:00 CEST']));
        file_put_contents($this->dir . '/update.log', "== backup\nok\n");
        SoftwareUpdate::$isActive = ['true'];

        // Maintenance through the cache driver, so the test never takes the real portal down.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['retry' => 20]);
        try {
            $this->get('/settings')->assertStatus(503);
            $this->getJson('/settings/update/status')->assertOk()
                ->assertJsonPath('running', true)
                ->assertJsonPath('status.message', 'Updating the database.')
                ->assertJsonPath('log', ['== backup', 'ok']);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
        $this->assertFileDoesNotExist(storage_path('framework/down'));

        // A run that died without writing its result is shown as stopped, not running forever.
        SoftwareUpdate::$isActive = ['false'];
        $this->getJson('/settings/update/status')->assertJsonPath('running', false)->assertJsonPath('status.state', 'failed');

        $this->actingAsOperator('view');
        $this->getJson('/settings/update/status')->assertForbidden();
        $this->post('/settings/update/start')->assertForbidden();
    }
}
