<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Currency;
use App\Support\Db;
use App\Support\RadiusRepository;
use App\Support\StaticIpPool;
use App\Support\UsageCaps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/** Service plans and static IP pools. */
final class ServiceController extends Controller
{
    /** Plan fields the form edits or carries through unchanged (legacy hidden inputs). */
    private const SERVICE_FIELDS = ['name', 'group_name', 'description', 'download_speed', 'upload_speed', 'burst_limit', 'burst_threshold', 'burst_time',
        'priority', 'ip_mode', 'ip_pool', 'session_timeout', 'idle_timeout', 'simultaneous_use', 'download_quota', 'upload_quota', 'total_quota', 'cap_action', 'cap_service_group',
        'expiration_behavior', 'billing_mode', 'expired_service_group', 'price', 'currency', 'custom_group_attrs'];

    public function index(): Response
    {
        $rows = Db::select('SELECT s.*, e.name AS expired_service_name, slow.name AS cap_service_name, COUNT(c.id) AS clients FROM service_metadata s LEFT JOIN service_metadata e ON e.group_name=s.expired_service_group LEFT JOIN service_metadata slow ON slow.group_name=s.cap_service_group LEFT JOIN customer_profiles c ON c.service_group=s.group_name GROUP BY s.id, e.name, slow.name ORDER BY s.name');
        return Inertia::render('services/Index', [
            'services' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'group' => (string) $r['group_name'],
                'download' => (string) $r['download_speed'],
                'upload' => (string) $r['upload_speed'],
                'expired' => (string) ($r['expired_service_name'] ?: ($r['expired_service_group'] ?: '-')),
                'price' => $r['price'] . ' ' . $r['currency'],
                'cap' => UsageCaps::capBytes($r['total_quota']) === 0 ? 'No cap' : (0 + $r['total_quota']) . ' GB, ' . ($r['cap_action'] === 'throttle' ? 'then ' . ($r['cap_service_name'] ?: $r['cap_service_group']) : 'notify only'),
                'enabled' => (bool) $r['enabled'],
                'clients' => (int) $r['clients'],
            ], $rows),
        ]);
    }

    public function form(?string $id = null): Response
    {
        $r = $id ? Db::row('SELECT * FROM service_metadata WHERE id=?', [$id]) : null;
        abort_if($id && !$r, 404, 'Service plan not found.');
        $expiredOptions = ['' => 'Use default expired service'];
        foreach (Db::select('SELECT group_name, name FROM service_metadata WHERE enabled=1 AND (? IS NULL OR id <> ?) ORDER BY name', [$id ? (int) $id : null, $id ? (int) $id : null]) as $service) {
            $expiredOptions[$service['group_name']] = ($service['name'] ?: $service['group_name']) . ' (' . $service['group_name'] . ')';
        }
        $values = [];
        foreach (self::SERVICE_FIELDS as $field) {
            $values[$field] = (string) ($r[$field] ?? '');
        }
        $values = ['id' => $r ? (int) $r['id'] : ''] + $values + ['enabled' => $r ? (bool) $r['enabled'] : true];
        if (!$r) {
            $values = array_merge($values, ['cap_action' => 'notify', 'ip_mode' => 'pool', 'simultaneous_use' => '1', 'billing_mode' => 'prepaid', 'price' => '0.00', 'currency' => Currency::code()]);
        }
        return Inertia::render('services/Form', [
            'service' => $values,
            'clientCount' => $r ? (int) Db::value('SELECT COUNT(*) FROM customer_profiles WHERE service_group=?', [$r['group_name']]) : null,
            'expiredOptions' => $expiredOptions,
            'capOptions' => array_diff_key($expiredOptions, ['' => true]),
            'canManage' => Auth::can('services.manage'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        Auth::requireCan('services.manage');
        $input = $this->formInput($request) + array_fill_keys(self::SERVICE_FIELDS, '');
        $id = (int) ($input['id'] ?? 0) ?: null;
        // Vendor rates are derived from the speeds; billing period is always monthly.
        $input['mikrotik_rate_limit'] = '';
        $input['billing_period'] = 'monthly';
        $input['currency'] = Currency::code();
        $before = $id ? Db::row('SELECT * FROM service_metadata WHERE id=?', [$id]) : null;
        try {
            $saved = RadiusRepository::saveService($input, $id);
        } catch (Throwable $e) {
            return $this->failKeepingInput($e, $id ? '/services/' . $id . '/edit' : '/services/create', [
                'group_name' => '/group/i', 'download_speed' => '/speed/i', 'expired_service_group' => '/expired/i', 'custom_group_attrs' => '/attribute/i',
            ]);
        }
        Audit::log($id ? 'update' : 'create', 'service', (string) $saved, $before, $input);
        return $this->ok('/services/' . $saved . '/edit', 'Service saved and group attributes updated.');
    }

    public function delete(string $id): RedirectResponse
    {
        Auth::requireCan('danger.delete');
        $before = Db::row('SELECT * FROM service_metadata WHERE id=?', [$id]);
        try {
            RadiusRepository::deleteService((int) $id);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/services');
        }
        Audit::log('delete', 'service', $id, $before);
        return $this->ok('/services', 'Service deleted.');
    }

    public function pools(): Response
    {
        $assigned = array_column(Db::select('SELECT framed_ip_address FROM customer_profiles WHERE framed_ip_address IS NOT NULL AND framed_ip_address <> ""'), 'framed_ip_address');
        return Inertia::render('services/Pools', [
            'pools' => array_map(static function (array $p) use ($assigned) {
                $ips = StaticIpPool::parse((string) $p['ip_ranges']);
                return [
                    'id' => (int) $p['id'],
                    'name' => (string) $p['name'],
                    'key' => (string) $p['pool_key'],
                    'ips' => count($ips),
                    'price' => Currency::amount($p['price'] ?? 0, $p['currency'] ?? null),
                    'enabled' => (bool) $p['enabled'],
                    'assigned' => count(array_intersect($assigned, $ips)),
                ];
            }, Db::select('SELECT * FROM static_ip_pools ORDER BY name')),
        ]);
    }

    public function poolForm(?string $id = null): Response
    {
        Auth::requireCan('services.manage');
        $pool = $id ? Db::row('SELECT * FROM static_ip_pools WHERE id=?', [$id]) : null;
        abort_if($id && !$pool, 404, 'IP pool not found.');
        return Inertia::render('services/PoolForm', ['pool' => [
            'id' => $pool ? (int) $pool['id'] : '',
            'name' => (string) ($pool['name'] ?? ''),
            'pool_key' => (string) ($pool['pool_key'] ?? ''),
            'ip_ranges' => (string) ($pool['ip_ranges'] ?? ''),
            'price' => (string) ($pool['price'] ?? '0.00'),
            'currency' => Currency::code(),
            'description' => (string) ($pool['description'] ?? ''),
            'enabled' => $pool ? (bool) $pool['enabled'] : true,
        ], 'isNew' => !$pool]);
    }

    public function poolSave(Request $request): RedirectResponse
    {
        Auth::requireCan('services.manage');
        $input = $this->formInput($request);
        $id = (int) ($input['id'] ?? 0) ?: null;
        $input['pool_key'] = trim((string) ($input['pool_key'] ?? '')) ?: Format::slugKey((string) ($input['name'] ?? ''));
        $before = $id ? Db::row('SELECT * FROM static_ip_pools WHERE id=?', [$id]) : null;
        try {
            $id = StaticIpPool::save($input, $id);
        } catch (Throwable $e) {
            return $this->failKeepingInput($e, $id ? '/services/ip-pools/' . $id . '/edit' : '/services/ip-pools/create', ['ip_ranges' => '/\bIP|range|address/i', 'pool_key' => '/key/i']);
        }
        Audit::log($before ? 'update' : 'create', 'static_ip_pool', (string) $id, $before, $input);
        return $this->ok('/services/ip-pools', 'Static IP pool saved.');
    }

    public function poolDelete(string $id): RedirectResponse
    {
        Auth::requireCan('services.manage');
        $pool = Db::row('SELECT * FROM static_ip_pools WHERE id=?', [$id]);
        if (!$pool) {
            return $this->fail('IP pool not found.', '/services/ip-pools');
        }
        try {
            StaticIpPool::delete((int) $id);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/services/ip-pools');
        }
        Audit::log('delete', 'static_ip_pool', $id, $pool);
        return $this->ok('/services/ip-pools', 'Static IP pool deleted.');
    }
}
