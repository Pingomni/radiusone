<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\SoftwareUpdate;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SoftwareUpdateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = (string) getenv('RADIUSONE_TEST_DIR');
        foreach (['/update/request', '/update-status/status.json', '/update-status/update.log', '/update-status.json', '/update.log'] as $file) {
            @unlink($this->dir . $file);
        }
        @rmdir($this->dir . '/update');
        @rmdir($this->dir . '/update-status');
    }

    /** What radiusone-update.path waits for: the folder the portal leaves requests in (ops/radiusone-postinstall). */
    private function ready(): void
    {
        @mkdir($this->dir . '/update', 0750);
        @mkdir($this->dir . '/update-status', 0750);
    }

    /** GitHub's answer for the latest release; $asset false: the release has no download yet. */
    private function release(string $version, bool $asset = true): void
    {
        // A fresh client each time: the first matching fake would otherwise keep answering.
        Http::swap(new Factory);
        Http::fake(['api.github.com/repos/n-geraldo/radiusone/releases/latest' => Http::response([
            'tag_name' => 'v' . $version,
            'html_url' => 'https://github.com/n-geraldo/radiusone/releases/tag/v' . $version,
            'published_at' => '2026-10-27T10:00:00Z',
            'body' => "## {$version}\n- New things",
            'assets' => $asset ? [['name' => "radiusone-{$version}.tar.gz"], ['name' => "radiusone-{$version}.tar.gz.sha256"]] : [],
        ])]);
    }

    /** @return array<string, mixed>|null */
    private function request(): ?array
    {
        $file = $this->dir . '/update/request';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    public function test_the_update_addresses_opened_as_pages_go_to_settings(): void
    {
        $this->actingAsOperator();
        // A reload, Back or a reopened tab after the update was started: no 405, just the Settings page.
        $this->get('/settings/update/start')->assertRedirect('/settings');
        $this->get('/settings/update/check')->assertRedirect('/settings');
        $this->get('/settings/update/move')->assertRedirect('/settings');
        // Any other button-only address opened as a page gets the portal's own error page.
        $this->get('/settings/license')->assertStatus(405)->assertInertia(fn ($page) => $page->where('title', 'Not a page'));
    }

    public function test_without_the_request_folder_the_page_says_how_to_enable_updates(): void
    {
        $this->actingAsOperator();
        $this->release('9.1.0');
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('update.current', (string) config('app.version'))
            ->where('update.ready', false)
            ->where('update.running', false)
            ->where('update.move', false)
            ->where('update.check', null));
        // Checking works anywhere; installing needs radiusone-update set up.
        $this->post('/settings/update/check')->assertSessionHas('flash.message', 'Version 9.1.0 is available.');
        $this->post('/settings/update/start')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'sudo radiusone-update'));
        $this->assertNull($this->request());
        // Other tabs do not build it.
        $this->get('/settings?tab=branding')->assertInertia(fn ($page) => $page->where('update', null));
    }

    public function test_check_then_update_to_the_newest_release(): void
    {
        $this->actingAsOperator();
        $this->ready();
        $this->release('9.1.0');
        $this->post('/settings/update/check')->assertSessionHas('flash.message', 'Version 9.1.0 is available.');
        $this->get('/settings')->assertInertia(fn ($page) => $page
            ->where('update.ready', true)
            ->where('update.check.newer', true)
            ->where('update.check.latest', '9.1.0')
            ->where('update.check.published', '2026-10-27')
            ->where('update.check.url', 'https://github.com/n-geraldo/radiusone/releases/tag/v9.1.0')
            ->where('update.check.notes', "## 9.1.0\n- New things"));

        $this->post('/settings/update/start')->assertRedirect('/settings')
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Installing RadiusOne 9.1.0'));
        $this->assertSame(['action' => 'install', 'tag' => 'v9.1.0'], array_intersect_key((array) $this->request(), ['action' => 1, 'tag' => 1]));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="update" AND entity_type="software" AND entity_id="9.1.0"'));
        // One at a time.
        $this->assertTrue(SoftwareUpdate::running());
        $this->post('/settings/update/start')->assertSessionHas('flash.message', 'An update is already running.');
    }

    public function test_up_to_date_unpackaged_and_failed_checks(): void
    {
        $this->actingAsOperator();
        $this->ready();
        $this->release((string) config('app.version'));
        $this->post('/settings/update/check')->assertSessionHas('flash.message', 'RadiusOne is up to date (' . config('app.version') . ').');
        // Nothing newer: nothing to start.
        $this->post('/settings/update/start')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Check for updates first'));

        // A release whose download is still being built is not installed.
        $this->release('9.1.0', asset: false);
        $this->post('/settings/update/check');
        $this->post('/settings/update/start')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'has no download yet'));
        $this->assertNull($this->request());

        Http::swap(new Factory);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->post('/settings/update/check')->assertSessionHas('flash.type', 'danger')
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'HTTP 404'));
    }

    public function test_a_server_from_before_2_3_is_offered_the_move(): void
    {
        $this->actingAsOperator();
        $this->ready();
        $this->post('/settings/update/move')->assertSessionHas('flash.message', 'This server already uses the RadiusOne layout.');
        $this->assertNull($this->request());

        // The app outside /opt/radiusone (it was /opt/freeradius-web-laravel).
        SoftwareUpdate::$home = '/opt/radiusone';
        $this->get('/settings')->assertInertia(fn ($page) => $page->where('update.move', true));
        $this->post('/settings/update/move')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Moving to the RadiusOne layout'));
        $this->assertSame('move', $this->request()['action'] ?? null);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="update" AND entity_type="software" AND entity_id="move"'));
    }

    public function test_progress_is_readable_during_maintenance_and_needs_settings_access(): void
    {
        $this->actingAsOperator();
        $this->ready();
        file_put_contents($this->dir . '/update-status/status.json', json_encode(['state' => 'running', 'action' => 'install', 'step' => 'migrate', 'from' => '2.0.1', 'to' => '9.1.0', 'started' => date('c')]));
        file_put_contents($this->dir . '/update-status/update.log', "== backup\nok\n");

        // Maintenance through the cache driver, so the test never takes the real portal down.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['retry' => 20]);
        try {
            $this->get('/settings')->assertStatus(503);
            $this->getJson('/settings/update/status')->assertOk()
                ->assertJsonPath('running', true)
                ->assertJsonPath('status.message', 'Installing RadiusOne 9.1.0.')
                ->assertJsonPath('log', ['== backup', 'ok']);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
        $this->assertFileDoesNotExist(storage_path('framework/down'));

        // A run that died without writing its result is shown as stopped after the updater's hour.
        file_put_contents($this->dir . '/update-status/status.json', json_encode(['state' => 'running', 'to' => '9.1.0', 'started' => date('c', time() - 7200)]));
        $this->getJson('/settings/update/status')->assertJsonPath('running', false)->assertJsonPath('status.state', 'failed');
        file_put_contents($this->dir . '/update-status/status.json', json_encode(['state' => 'done', 'from' => '2.0.1', 'to' => '9.1.0', 'finished' => '2026-10-27T10:05:00+01:00']));
        $this->getJson('/settings/update/status')->assertJsonPath('status.state', 'ok')->assertJsonPath('status.message', 'Updated from 2.0.1 to 9.1.0.')
            ->assertJsonPath('status.at', '2026-10-27 10:05');

        $this->actingAsOperator('view');
        $this->getJson('/settings/update/status')->assertForbidden();
        $this->post('/settings/update/start')->assertForbidden();
        $this->post('/settings/update/move')->assertForbidden();
    }

    public function test_the_result_of_the_updater_before_2_3_is_still_shown(): void
    {
        $this->actingAsOperator();
        file_put_contents($this->dir . '/update-status.json', json_encode(['state' => 'ok', 'step' => 'done', 'message' => 'Updated from 2.2.4 to 2.3.0.', 'from' => '2.2.4', 'to' => '2.3.0', 'at' => '2026-10-27 10:00']));
        file_put_contents($this->dir . '/update.log', "done: 2.2.4 → 2.3.0\n");
        $this->getJson('/settings/update/status')->assertJsonPath('status.message', 'Updated from 2.2.4 to 2.3.0.')
            ->assertJsonPath('log', ['done: 2.2.4 → 2.3.0']);
    }
}
