<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\NasTables;
use Tests\TestCase;

/** Answers walk() from a fixed OID => value list, like a device would (values as the SNMP client decodes them). */
final class RecordedSnmp
{
    public function __construct(private readonly array $values) {}

    public function walk(string $root, int $limit = 2000): array
    {
        return array_slice(array_filter($this->values, static fn ($oid) => str_starts_with($oid, $root . '.'), ARRAY_FILTER_USE_KEY), 0, $limit, true);
    }
}

final class NasTablesTest extends TestCase
{
    private const NAS = ['id' => 1, 'nasname' => '192.0.2.1'];

    private function device(): RecordedSnmp
    {
        $mac = static fn (string $hex) => hex2bin(str_replace(':', '', $hex));
        return new RecordedSnmp([
            // ifName
            '1.3.6.1.2.1.31.1.1.1.1.1' => 'ether1', '1.3.6.1.2.1.31.1.1.1.1.2' => 'bridge-lan', '1.3.6.1.2.1.31.1.1.1.1.3' => 'vlan15-uplink',
            // ipAddrTable: address, ifIndex, mask
            '1.3.6.1.2.1.4.20.1.1.192.0.2.1' => '192.0.2.1', '1.3.6.1.2.1.4.20.1.2.192.0.2.1' => 1, '1.3.6.1.2.1.4.20.1.3.192.0.2.1' => '255.255.255.0',
            '1.3.6.1.2.1.4.20.1.1.198.51.100.1' => '198.51.100.1', '1.3.6.1.2.1.4.20.1.2.198.51.100.1' => 3, '1.3.6.1.2.1.4.20.1.3.198.51.100.1' => '255.255.255.252',
            // ipNetToMediaTable: index ifIndex.IP; MAC and type (3 dynamic, 2 invalid)
            '1.3.6.1.2.1.4.22.1.2.1.192.0.2.20' => $mac('84:c1:c1:81:b9:30'), '1.3.6.1.2.1.4.22.1.4.1.192.0.2.20' => 3,
            '1.3.6.1.2.1.4.22.1.2.1.192.0.2.9' => $mac('e8:b6:c2:1d:5f:e0'), '1.3.6.1.2.1.4.22.1.4.1.192.0.2.9' => 4,
            '1.3.6.1.2.1.4.22.1.2.1.192.0.2.30' => '', '1.3.6.1.2.1.4.22.1.4.1.192.0.2.30' => 2,
            // Q-BRIDGE FDB: index FDB id . MAC bytes; bridge port, status. Bridge port 7 is ifIndex 2.
            '1.3.6.1.2.1.17.7.1.2.2.1.2.15.72.169.138.159.205.245' => 7, '1.3.6.1.2.1.17.7.1.2.2.1.3.15.72.169.138.159.205.245' => 3,
            '1.3.6.1.2.1.17.1.4.1.2.7' => 2,
            // BGP4-MIB: one established peer, one idle that never came up; no update counters (like RouterOS).
            '1.3.6.1.2.1.15.3.1.2.203.0.113.1' => 6, '1.3.6.1.2.1.15.3.1.3.203.0.113.1' => 2, '1.3.6.1.2.1.15.3.1.5.203.0.113.1' => '203.0.113.2',
            '1.3.6.1.2.1.15.3.1.9.203.0.113.1' => 64500, '1.3.6.1.2.1.15.3.1.16.203.0.113.1' => 3 * 86400,
            '1.3.6.1.2.1.15.3.1.2.203.0.113.5' => 1, '1.3.6.1.2.1.15.3.1.3.203.0.113.5' => 2, '1.3.6.1.2.1.15.3.1.5.203.0.113.5' => '203.0.113.6',
            '1.3.6.1.2.1.15.3.1.9.203.0.113.5' => 64501, '1.3.6.1.2.1.15.3.1.16.203.0.113.5' => 0,
            // OSPF-MIB neighbour in state full
            '1.3.6.1.2.1.14.10.1.1.198.51.100.2.0' => '198.51.100.2', '1.3.6.1.2.1.14.10.1.3.198.51.100.2.0' => '10.255.0.2',
            '1.3.6.1.2.1.14.10.1.5.198.51.100.2.0' => 1, '1.3.6.1.2.1.14.10.1.6.198.51.100.2.0' => 8, '1.3.6.1.2.1.14.10.1.7.198.51.100.2.0' => 6,
        ]);
    }

    public function test_tables_are_read_into_named_rows(): void
    {
        $device = $this->device();
        $read = static fn (string $table) => NasTables::read(self::NAS, $table, $device);

        $this->assertSame([['192.0.2.1/24', 'ether1'], ['198.51.100.1/30', 'vlan15-uplink']], $read('addresses')['rows']);

        $arp = $read('arp');
        $this->assertSame([['192.0.2.9', 'E8:B6:C2:1D:5F:E0', 'ether1', 'static'], ['192.0.2.20', '84:C1:C1:81:B9:30', 'ether1', 'dynamic']], $arp['rows']);
        $this->assertSame('1 invalid entry (failed lookups) not shown.', $arp['note']);

        $this->assertSame([['48:A9:8A:9F:CD:F5', '15', 'bridge-lan', 'learned']], $read('mac')['rows']);

        $bgp = $read('bgp');
        // No update counters on this device: that column is left out.
        $this->assertSame(['Peer', 'Remote AS', 'State', 'In this state for', 'Local address'], $bgp['columns']);
        $this->assertSame(['203.0.113.1', 'AS64500', ['text' => 'established', 'tone' => 'success'], '3 days', '203.0.113.2'], $bgp['rows'][0]);
        $this->assertSame(['text' => 'idle', 'tone' => 'danger'], $bgp['rows'][1][2]);
        $this->assertSame('never up', $bgp['rows'][1][3]);

        $this->assertSame([['198.51.100.2', '10.255.0.2', ['text' => 'full', 'tone' => 'success'], '1', '6']], $read('ospf')['rows']);
    }

    public function test_a_device_without_the_mib_shows_why_the_table_is_empty(): void
    {
        $empty = NasTables::read(self::NAS, 'ospf', new RecordedSnmp([]));
        $this->assertSame([], $empty['rows']);
        $this->assertStringContainsString('does not publish OSPF over SNMP', $empty['empty']);
    }

    public function test_the_page_offers_tables_only_with_snmp_and_the_endpoint_explains_why(): void
    {
        $this->actingAsOperator('view');
        Db::exec('INSERT INTO nas (nasname, shortname, type, secret) VALUES ("192.0.2.10", "edge-1", "mikrotik", "s")');
        $id = (int) Db::pdo()->lastInsertId();
        $this->get('/nas/' . $id . '/monitor')->assertOk()->assertInertia(fn ($page) => $page->where('tables', null));
        $this->getJson('/nas/' . $id . '/monitor/tables/bgp')->assertOk()
            ->assertJsonPath('error', 'SNMP is not set up for this NAS. Set it up on the NAS SNMP tab to read its tables.');
        $this->getJson('/nas/' . $id . '/monitor/tables/routes')->assertNotFound();
    }
}
