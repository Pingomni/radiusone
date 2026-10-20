<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\Db;
use App\Support\ServerMonitor;

/** NAS presentation and lookups shared by the NAS and Monitoring controllers (legacy app/Http/nas.php). */
final class Nas
{
    /** Supported NAS platforms. The key is stored as nas_metadata.vendor and nas.type. */
    public const VENDORS = [
        'mikrotik' => ['label' => 'MikroTik RouterOS', 'summary' => 'PPPoE or local DHCP. Disconnects use RADIUS CoA, or the RouterOS API when switched on.', 'disconnect' => 'coa'],
        // Beta: written from Cisco documentation, not yet tested on a real router.
        'cisco' => ['label' => 'Cisco IOS XE', 'summary' => 'PPPoE or IPoE/ISG. Disconnects are sent as RADIUS disconnect requests (CoA). Not yet tested on a real Cisco router.', 'disconnect' => 'coa', 'beta' => true],
    ];

    /** Vendor name for lists and monitor pages; untested platforms are marked "(Beta)". */
    public static function vendorLabel(string $vendor): string
    {
        $known = self::VENDORS[strtolower($vendor)] ?? null;
        if ($known === null) {
            return $vendor;
        }
        return $known['label'] . (!empty($known['beta']) ? ' (Beta)' : '');
    }

    public const ADAPTERS = ['routeros_pppoe' => 'RouterOS PPPoE', 'routeros_dhcp' => 'RouterOS local DHCP', 'iosxe_pppoe' => 'ASR1000 IOS XE PPPoE', 'iosxe_ipoe' => 'ASR1000 IOS XE local DHCP/IPoE'];

    public const SETUP_STEPS = [
        'mikrotik' => [
            ['RADIUS client', 'Add this server under /radius with the same shared secret and the NAS IP as src-address.'],
            ['Services', 'Turn on RADIUS for PPP and/or local DHCP, each pointing at the RADIUS port set up for it.'],
            ['Interim accounting', 'Set an interim update, e.g. /ppp aaa set interim-update=1m, so Monitoring and disconnects see fresh sessions.'],
            ['Disconnect (CoA)', 'Accept RADIUS disconnects: /radius incoming set accept=yes port=3799. RouterOS only accepts them from the RADIUS servers listed under /radius, signed with the shared secret.'],
            ['API (optional)', 'Only if you switch on the API tab: enable api-ssl on TCP 8729 with a trusted certificate and restrict it to this server.'],
            ['SNMP', 'Optional: add a read-only community or v3 user for this server only (see the SNMP tab).'],
        ],
        'cisco' => [
            ['RADIUS server', 'Define this server in the AAA group with the same shared secret, sourced from the NAS IP.'],
            ['Subscriber login (AAA)', 'Point PPPoE or IPoE/ISG login and accounting at the matching RADIUS port.'],
            ['Interim accounting', 'Enable periodic accounting (e.g. every 1–5 minutes) so Monitoring and disconnects see fresh sessions.'],
            ['Dynamic authorization', 'Allow this server as a CoA client on the CoA port; verify on your IOS XE release.'],
            ['SNMP', 'Optional: add a read-only community or v3 user limited by an ACL to this server (see the SNMP tab).'],
        ],
    ];

    /** NAS row for the monitor pages; the RADIUS server itself is the pseudo-NAS ServerMonitor::ID. */
    public static function monitorRow(string|int $id): array
    {
        if ((int) $id === ServerMonitor::ID) {
            return ServerMonitor::pseudoNas();
        }
        $nas = Db::row('SELECT n.*, m.vendor, m.enabled FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE n.id=?', [(int) $id]);
        abort_if(!$nas, 404, 'NAS device not found.');
        return $nas;
    }

    public static function bps(mixed $bps): string
    {
        if (!is_numeric($bps)) {
            return '…';
        }
        $units = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s', 'Tbit/s'];
        $i = 0;
        while ($bps >= 1000 && $i < count($units) - 1) {
            $bps /= 1000;
            $i++;
        }
        return number_format((float) $bps, $i ? 1 : 0) . ' ' . $units[$i];
    }

    /**
     * Pings every host at once, so a page takes about one timeout in total instead of one per device.
     *
     * @return array<string, array{ok: bool, label: string, class: string}>
     */
    public static function pingStatuses(array $hosts): array
    {
        $results = [];
        $running = [];
        foreach (array_unique(array_map('trim', $hosts)) as $host) {
            if ($host === '') {
                $results[$host] = ['ok' => false, 'label' => 'missing host', 'class' => 'warning'];
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $results[$host] = ['ok' => false, 'label' => 'invalid address', 'class' => 'warning'];
                continue;
            }
            $process = function_exists('proc_open')
                ? @proc_open(['ping', '-c', '1', '-W', '1', $host], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes)
                : false;
            if (is_resource($process)) {
                $running[$host] = $process;
            } else {
                $results[$host] = ['ok' => false, 'label' => 'ping disabled', 'class' => 'warning'];
            }
        }
        foreach ($running as $host => $process) {
            $results[$host] = proc_close($process) === 0
                ? ['ok' => true, 'label' => 'reachable', 'class' => 'success']
                : ['ok' => false, 'label' => 'no reply', 'class' => 'danger'];
        }
        return $results;
    }
}
