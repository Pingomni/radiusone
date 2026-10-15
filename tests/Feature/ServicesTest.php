<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use Tests\TestCase;

final class ServicesTest extends TestCase
{
    private function plan(array $overrides = []): array
    {
        return array_merge([
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true,
            'download_speed' => '50', 'upload_speed' => '10', 'burst_limit' => '', 'burst_threshold' => '', 'burst_time' => '', 'priority' => '',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1',
            'download_quota' => '', 'upload_quota' => '', 'total_quota' => '', 'expiration_behavior' => '', 'billing_mode' => 'prepaid',
            'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'custom_group_attrs' => '',
        ], $overrides);
    }

    public function test_service_plan_lifecycle(): void
    {
        $this->actingAsOperator();
        $this->get('/services/create')->assertOk()->assertInertia(fn ($page) => $page->component('services/Form')->where('service.ip_mode', 'pool'));

        $this->post('/services/save', $this->plan())->assertSessionHas('flash.type', 'success');
        $id = (int) Db::value('SELECT id FROM service_metadata WHERE group_name="home50"');
        $this->assertGreaterThan(0, $id);
        $this->assertGreaterThan(0, (int) Db::value('SELECT COUNT(*) FROM radgroupreply WHERE groupname="home50"'));

        $this->get('/services')->assertOk()->assertInertia(fn ($page) => $page->component('services/Index')->where('services.0.group', 'home50')->where('services.0.clients', 0));
        $this->get('/services/' . $id . '/edit')->assertOk()->assertInertia(fn ($page) => $page->where('service.name', 'Home 50')->where('clientCount', 0));

        // A speed without its pair is a field-level error that keeps the operator on the form.
        $this->post('/services/save', $this->plan(['id' => $id, 'upload_speed' => '']))
            ->assertRedirect('/services/' . $id . '/edit')->assertSessionHasErrors('download_speed');

        $this->post('/services/' . $id . '/delete')->assertRedirect('/services');
        $this->assertNull(Db::row('SELECT id FROM service_metadata WHERE id=?', [$id]));
    }

    public function test_view_role_sees_read_only_plans(): void
    {
        $this->actingAsOperator('view');
        $this->get('/services')->assertOk();
        $this->get('/services/create')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->post('/services/save', $this->plan())->assertForbidden();
        $this->get('/services/ip-pools/create')->assertForbidden();
    }

    public function test_static_ip_pools(): void
    {
        $this->actingAsOperator();
        $this->post('/services/ip-pools/save', ['id' => '', 'name' => 'Office Block', 'pool_key' => '', 'ip_ranges' => '10.0.0.10-12', 'price' => '5', 'currency' => 'EUR', 'description' => '', 'enabled' => true])
            ->assertRedirect('/services/ip-pools');
        $pool = Db::row('SELECT * FROM static_ip_pools');
        $this->assertSame('office_block', $pool['pool_key']);

        $this->get('/services/ip-pools')->assertOk()->assertInertia(fn ($page) => $page->component('services/Pools')->where('pools.0.ips', 3)->where('pools.0.assigned', 0));
        $this->get('/services/ip-pools/' . $pool['id'] . '/edit')->assertOk()->assertInertia(fn ($page) => $page->where('pool.ip_ranges', '10.0.0.10-12'));

        $this->post('/services/ip-pools/save', ['id' => $pool['id'], 'name' => 'Office Block', 'pool_key' => 'office_block', 'ip_ranges' => 'not-an-ip', 'price' => '5', 'currency' => 'EUR', 'description' => '', 'enabled' => true])
            ->assertRedirect('/services/ip-pools/' . $pool['id'] . '/edit')->assertSessionHas('flash.type', 'danger');

        $this->post('/services/ip-pools/' . $pool['id'] . '/delete')->assertRedirect('/services/ip-pools');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM static_ip_pools'));
    }
}
