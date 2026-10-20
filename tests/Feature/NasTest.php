<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\NasController;
use App\Http\Support\Nas;
use App\Support\Db;
use App\Support\NasRepository;
use Tests\TestCase;

final class NasTest extends TestCase
{
    private function createNas(): int
    {
        $this->post('/nas/save', ['id' => '', 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'bras-01', 'nasname' => '10.10.0.1', 'secret' => 'radius-secret-1', 'description' => 'Rack A', 'enabled' => true])
            ->assertSessionHas('flash.type', 'success');
        return (int) Db::value('SELECT id FROM nas WHERE nasname="10.10.0.1"');
    }

    public function test_create_list_and_edit_tabs(): void
    {
        $this->actingAsOperator();
        $this->get('/nas/create')->assertOk()->assertInertia(fn ($page) => $page->component('nas/Form')->where('nas', null)->where('device.vendor', 'mikrotik'));
        $id = $this->createNas();
        $this->assertGreaterThan(0, $id);

        $this->get('/nas')->assertOk()->assertInertia(fn ($page) => $page
            ->component('nas/Index')
            ->where('devices.0.name', 'bras-01')
            ->where('devices.0.update.text', 'Waiting for update'));

        $this->get('/nas/' . $id . '/edit')->assertOk()->assertInertia(fn ($page) => $page
            ->where('nas.ip', '10.10.0.1')
            ->where('device.secret', '')
            ->where('api.apiPasswordSaved', false)
            ->where('api.api_enabled', false)
            ->where('device.coa_enabled', true)
            ->where('device.coa_port', '3799')
            ->where('nas.stats.2.value', 'CoA ready')
            ->has('nas.stats', 4)
            ->has('sessions', 0));
    }

    public function test_changes_saved_before_freeradius_started_are_recorded_as_in_effect(): void
    {
        $this->actingAsOperator();
        $id = $this->createNas();
        $this->post('/nas/save', ['id' => $id, 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'bras-01', 'nasname' => '10.10.0.1', 'secret' => '', 'enabled' => true, 'coa_enabled' => true, 'coa_port' => '3799'])
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Restart FreeRADIUS'));
        Db::exec('UPDATE nas_metadata SET updated_at = NOW() - INTERVAL 10 MINUTE WHERE nas_id=?', [$id]);
        $revision = (int) Db::value('SELECT config_revision FROM nas_metadata WHERE nas_id=?', [$id]);

        // FreeRADIUS started before the save: still waiting.
        $this->assertSame([], NasRepository::recordLoadedNasRevisions(time() - 1200));
        $this->get('/nas')->assertInertia(fn ($page) => $page->where('devices.0.update.text', 'Waiting for update'));

        // Started after it: in effect, with the evidence recorded.
        $this->assertSame([$id => $revision], NasRepository::recordLoadedNasRevisions(time() - 60));
        $this->assertSame($revision, (int) Db::value('SELECT applied_revision FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->assertStringContainsString('recorded automatically', (string) Db::value('SELECT applied_evidence FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->get('/nas')->assertInertia(fn ($page) => $page->where('devices.0.update.text', 'Up to date'));
        $this->assertSame([], NasRepository::recordLoadedNasRevisions(time()));

        // A CoA-only change needs no restart.
        $this->post('/nas/save', ['id' => $id, 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'bras-01', 'nasname' => '10.10.0.1', 'secret' => '', 'enabled' => true, 'coa_enabled' => true, 'coa_port' => '1700'])
            ->assertSessionHas('flash.message', 'NAS saved. These settings apply at once.');

        $this->artisan('frweb:mark-nas-applied --auto')->assertExitCode(0);
    }

    public function test_api_tab_keeps_device_fields_and_secret(): void
    {
        $this->actingAsOperator();
        $id = $this->createNas();
        $secret = Db::value('SELECT secret FROM nas WHERE id=?', [$id]);
        $revision = (int) Db::value('SELECT config_revision FROM nas_metadata WHERE nas_id=?', [$id]);

        $this->post('/nas/save', ['id' => $id, 'section' => 'api', 'api_enabled' => true, 'api_user' => 'portal', 'api_password' => 'api-pass-123'])
            ->assertRedirect('/nas/' . $id . '/edit?tab=api');
        $this->assertSame(1, (int) Db::value('SELECT api_enabled FROM nas_metadata WHERE nas_id=?', [$id]));
        // API settings are used by the portal only: no RADIUS update needed.
        $this->assertSame($revision, (int) Db::value('SELECT config_revision FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->get('/nas/' . $id . '/edit')->assertInertia(fn ($page) => $page->where('nas.stats.2.value', 'API ready'));
        $this->assertSame('bras-01', Db::value('SELECT shortname FROM nas WHERE id=?', [$id]));
        $this->assertSame($secret, Db::value('SELECT secret FROM nas WHERE id=?', [$id]));
        $this->assertSame('portal', Db::value('SELECT api_user FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->assertStringNotContainsString('api-pass-123', (string) Db::value('SELECT GROUP_CONCAT(COALESCE(after_json,"")) FROM audit_logs'));
        $this->assertStringNotContainsString('radius-secret-1', (string) Db::value('SELECT GROUP_CONCAT(COALESCE(after_json,"")) FROM audit_logs'));

        // Device tab with the checkbox off disables RADIUS but keeps the API login.
        $this->post('/nas/save', ['id' => $id, 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'bras-01', 'nasname' => '10.10.0.1', 'secret' => '', 'description' => 'Rack A', 'enabled' => false, 'coa_enabled' => true, 'coa_port' => '3799']);
        $this->assertSame(0, (int) Db::value('SELECT enabled FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->assertSame('portal', Db::value('SELECT api_user FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->assertSame($revision + 1, (int) Db::value('SELECT config_revision FROM nas_metadata WHERE nas_id=?', [$id]));

        // Switching the API off keeps the login; disconnects fall back to CoA.
        $this->post('/nas/save', ['id' => $id, 'section' => 'api', 'api_enabled' => false, 'api_user' => 'portal', 'api_password' => '']);
        $this->assertSame(0, (int) Db::value('SELECT api_enabled FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->assertSame('portal', Db::value('SELECT api_user FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->assertSame(1, (int) Db::value('SELECT coa_enabled FROM nas_metadata WHERE nas_id=?', [$id]));
    }

    public function test_invalid_vendor_is_a_field_error(): void
    {
        $this->actingAsOperator();
        $this->post('/nas/save', ['id' => '', 'section' => 'device', 'vendor' => 'pfsense', 'shortname' => 'x', 'nasname' => '10.0.0.9', 'secret' => 's', 'description' => ''])
            ->assertRedirect('/nas/create')->assertSessionHasErrors('vendor');
    }

    public function test_nas_address_must_be_an_ip_and_a_new_nas_needs_a_secret(): void
    {
        $this->actingAsOperator();
        foreach (['', 'router.example', '-c 100000', '10.0.0.300'] as $address) {
            $this->post('/nas/save', ['id' => '', 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'x', 'nasname' => $address, 'secret' => 's', 'description' => ''])
                ->assertRedirect('/nas/create')->assertSessionHasErrors('nasname');
        }
        $this->post('/nas/save', ['id' => '', 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'x', 'nasname' => '10.0.0.9', 'secret' => '', 'description' => ''])
            ->assertRedirect('/nas/create')->assertSessionHasErrors('secret');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM nas'));

        $this->post('/nas/save', ['id' => '', 'section' => 'device', 'vendor' => 'mikrotik', 'shortname' => 'v6', 'nasname' => ' 2001:db8::1 ', 'secret' => 's', 'description' => ''])
            ->assertSessionHas('flash.type', 'success');
        $this->assertSame('2001:db8::1', Db::value('SELECT nasname FROM nas'));
        $this->assertSame('invalid address', Nas::pingStatuses(['-c 100000'])['-c 100000']['label']);
    }

    public function test_snmp_save_is_guarded_like_the_other_nas_changes(): void
    {
        foreach (['save', 'delete', 'snmpSave'] as $action) {
            $route = app('router')->getRoutes()->getByAction(NasController::class . '@' . $action);
            $this->assertNotNull($route, $action);
            $this->assertContains('recent-auth', $route->gatherMiddleware(), $action);
        }
    }

    public function test_snmp_settings_and_no_access_tab(): void
    {
        $this->actingAsOperator();
        $id = $this->createNas();
        $this->post('/nas/' . $id . '/snmp', ['snmp_enabled' => true, 'snmp_port' => '161', 'snmp_host' => '', 'snmp_version' => '2c', 'snmp_timeout_ms' => '1500', 'snmp_community' => 'ro-community'])
            ->assertRedirect('/nas/' . $id . '/edit?tab=snmp');
        $this->get('/nas/' . $id . '/edit')->assertOk()->assertInertia(fn ($page) => $page
            ->where('snmp.values.snmp_enabled', true)
            ->where('snmp.values.snmp_community', 'ro-community')
            ->where('nas.stats.3.value', 'v2c'));

        // The Access tab was removed: no access props, and old links open the NAS page.
        $this->get('/nas/' . $id . '/edit')->assertOk()->assertInertia(fn ($page) => $page->missing('access'));
        $this->get('/nas/' . $id . '/access')->assertRedirect('/nas/' . $id . '/edit');
    }

    public function test_view_role_is_read_only_and_delete(): void
    {
        $this->actingAsOperator();
        $id = $this->createNas();

        $this->actingAsOperator('view');
        $this->get('/nas/' . $id . '/edit')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->where('snmp.values.snmp_community', ''));
        $this->post('/nas/' . $id . '/snmp', ['snmp_enabled' => true])->assertForbidden();

        $this->actingAsOperator();
        $this->post('/nas/' . $id . '/delete')->assertRedirect('/nas');
        $this->assertSame(1, (int) Db::value('SELECT pending_delete FROM nas_metadata WHERE nas_id=?', [$id]));
        $this->get('/nas/health')->assertRedirect('/monitoring');
    }
}
