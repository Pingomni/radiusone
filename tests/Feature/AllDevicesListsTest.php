<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Olt\OltChangelog;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The lists that span every device: sessions on every router, PON ports on every OLT. */
final class AllDevicesListsTest extends TestCase
{
    public function test_sessions_of_every_router_are_in_one_list(): void
    {
        $this->actingAsOperator();
        $a = $this->insertRow('nas', ['nasname' => '192.0.2.10', 'shortname' => 'r-centre', 'secret' => 'x']);
        $b = $this->insertRow('nas', ['nasname' => '192.0.2.20', 'shortname' => 'r-north', 'secret' => 'x']);
        $now = time();
        $at = static fn (int $ago) => date('Y-m-d H:i:s', $now - $ago);
        $live = $this->insertRow('radacct', ['username' => 'alice', 'nasipaddress' => '192.0.2.10', 'framedipaddress' => '100.64.0.5', 'callingstationid' => 'AA:BB:CC:00:00:01', 'acctstarttime' => $at(3600), 'acctupdatetime' => $at(30), 'acctoutputoctets' => 2048, 'acctinputoctets' => 1024]);
        $this->insertRow('radacct', ['username' => 'bob', 'nasipaddress' => '192.0.2.20', 'framedipaddress' => '100.64.0.9', 'acctstarttime' => $at(7200), 'acctupdatetime' => $at(20)]);
        $this->insertRow('radacct', ['username' => 'carol', 'nasipaddress' => '192.0.2.20', 'framedipaddress' => '100.64.0.7', 'acctstarttime' => $at(90000), 'acctupdatetime' => $at(80000)]);
        $this->insertRow('radacct', ['username' => 'dave', 'nasipaddress' => '192.0.2.20', 'acctstarttime' => $at(9000), 'acctupdatetime' => $at(8000), 'acctstoptime' => $at(8000)]);

        $this->get('/sessions')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('clients/Sessions')
            ->where('counts', ['online' => 2, 'stale' => 1, 'all' => 3])->where('result.total', 2)
            ->where('result.rows.0.username', 'alice')->where('result.rows.0.router', 'r-centre')->where('result.rows.0.nasId', $a)
            ->where('result.rows.0.ip', '100.64.0.5')->where('result.rows.0.online', true)->where('result.rows.0.canDisconnect', true)->where('result.rows.0.id', $live));
        $this->get('/sessions?view=stale')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 1)->where('result.rows.0.username', 'carol')->where('result.rows.0.canDisconnect', false));
        $this->get('/sessions?view=all&nas=' . $b)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 2)->where('counts.online', 1));
        $this->get('/sessions?q=100.64.0.9')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 1)->where('result.rows.0.username', 'bob'));
        // People who may only look see the list without the Disconnect button.
        $this->actingAsOperator('view');
        $this->get('/sessions')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.rows.0.canDisconnect', false));
    }

    public function test_pon_ports_of_every_olt_are_in_one_list(): void
    {
        $this->actingAsOperator();
        $olts = [];
        foreach (['OLT-Centre', 'OLT-North'] as $i => $name) {
            $olts[] = $olt = $this->insertRow('olts', ['name' => $name, 'model' => 'MA5800', 'host' => '10.0.9.' . ($i + 2), 'enabled' => 1, 'rx_warning' => -27, 'rx_critical' => -30, 'max_onus_per_pon' => 2,
                'inventory' => json_encode(['pon' => ['0/1/0' => ['tx' => 7.1, 'autofind' => $i === 0, 'rogue' => 'Inexistent'], '0/1/1' => ['rogue' => $i === 1 ? 'Exist' : 'Inexistent']]])]);
            foreach ([0, 1] as $port) {
                $this->insertRow('olt_ports', ['olt_id' => $olt, 'if_index' => 100 + $port, 'name' => 'GPON 0/1/' . $port, 'kind' => 'pon', 'admin_up' => 1, 'oper_up' => 1, 'sampled_at' => time()]);
            }
        }
        foreach ([[$olts[0], 0, 0, 'online'], [$olts[0], 0, 1, 'offline'], [$olts[1], 0, 0, 'offline']] as [$olt, $port, $n, $status]) {
            $this->insertRow('olt_onus', ['olt_id' => $olt, 'frame' => 0, 'slot' => 1, 'port' => $port, 'ont_id' => $n, 'sn' => 'HWTC' . $olt . $port . $n, 'status' => $status, 'rx_dbm' => $status === 'online' ? -20 : null]);
        }

        $this->get('/ports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('olts/Ports')
            ->where('counts', ['all' => 4, 'used' => 2, 'problems' => 3])
            ->where('ports.0.olt', 'OLT-Centre')->where('ports.0.port', '0/1/0')->where('ports.0.online', 1)->where('ports.0.total', 2)->where('ports.0.free', 0)->where('ports.0.problems', ['Full'])
            ->where('ports.2.olt', 'OLT-North')->where('ports.2.problems', ['All ONUs offline', 'Autofind off'])->where('ports.2.danger', true)
            ->where('ports.3.problems', ['Rogue ONU']));
        $this->get('/ports?view=used&olt=' . $olts[1])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('ports', 1)->where('counts.all', 2));
        // A port's ONUs, in the list of all ONUs.
        $this->get('/onus?olt=' . $olts[0] . '&port=0/1/0')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.total', 2)->where('filters.port', '0/1/0'));
    }
    public function test_routers_and_olts_are_one_list_of_devices_and_olt_changes_one_changelog(): void
    {
        $this->actingAsOperator();
        $nas = $this->insertRow('nas', ['nasname' => '192.0.2.10', 'shortname' => 'r-centre', 'secret' => 'x']);
        $good = $this->insertRow('olts', ['name' => 'OLT-Centre', 'model' => 'huawei_ma5800_x7', 'host' => '10.0.9.2', 'enabled' => 1, 'last_sync_ok' => 1, 'last_sync_at' => date('Y-m-d H:i:s')]);
        $bad = $this->insertRow('olts', ['name' => 'OLT-North', 'model' => 'huawei_ma5800_x7', 'host' => '10.0.9.3', 'enabled' => 1, 'last_sync_ok' => 0, 'last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => 'No SNMP response']);
        $this->insertRow('olt_onus', ['olt_id' => $good, 'frame' => 0, 'slot' => 1, 'port' => 0, 'ont_id' => 0, 'sn' => 'HWTC00000001', 'description' => 'Shop', 'status' => 'online']);

        $this->get('/devices')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('monitoring/Devices')
            ->where('counts.all', 3)->where('counts.router', 1)->where('counts.olt', 2)
            ->where('devices.0.name', 'r-centre')->where('devices.0.kind', 'router')->where('devices.0.countHref', '/sessions?nas=' . $nas)
            ->where('devices.1.name', 'OLT-Centre')->where('devices.1.state', 'Working')->where('devices.1.count', '1 of 1 ONUs')->where('devices.1.countHref', '/onus?olt=' . $good)
            ->where('devices.2.name', 'OLT-North')->where('devices.2.state', 'Problem')->where('devices.2.tone', 'danger'));
        $this->get('/devices?view=olt')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('devices', 2));
        $this->get('/devices?view=problems')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('devices', fn ($d) => in_array('OLT-North', array_column(is_array($d) ? $d : $d->all(), 'name'), true)));

        OltChangelog::add($good, 'onu_reboot', 'HWTC00000001', '0/1/0:0');
        OltChangelog::add($bad, 'port_off', null, '0/1/3');
        $this->get('/olts/changelog')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('olts/Changelog')->has('changes', 2)
            ->where('changes', fn ($c) => array_column(is_array($c) ? $c : $c->all(), 'olt', 'action') == ['port_off' => 'OLT-North', 'onu_reboot' => 'OLT-Centre']));
    }
}
