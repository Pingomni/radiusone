<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Http\Support\Nas;
use App\Http\Support\Present;
use App\Support\Accounting;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Db;
use App\Support\NasMonitor;
use App\Support\RadiusRepository;
use App\Support\Secrets;
use App\Support\Snmp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/** NAS devices: list, edit tabs and saves, SNMP settings, delete. */
final class NasController extends Controller
{
    private static function rowSql(): string
    {
        return 'SELECT n.*, m.config_revision, m.applied_revision, m.pending_delete, m.vendor, m.enabled, m.coa_enabled, m.coa_port, m.api_host, m.api_user, (COALESCE(m.api_password, "") <> "") AS api_password_saved, '
            . RadiusRepository::apiEnabledSql('m') . ' AS api_enabled FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id';
    }

    public function index(): Response
    {
        $rows = Db::select('SELECT n.*, m.config_revision, m.applied_revision, m.pending_delete, m.vendor, m.enabled, m.coa_enabled, m.coa_port, m.api_host, m.api_user, (COALESCE(m.api_password, "") <> "") AS api_password_saved, ' . RadiusRepository::apiEnabledSql('m') . ' AS api_enabled, COUNT(r.radacctid) AS online_users FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id LEFT JOIN radacct r ON ' . Accounting::freshSql('r') . ' AND (r.nasipaddress=n.nasname OR r.nasipaddress=n.shortname) GROUP BY n.id ORDER BY n.shortname');
        return Inertia::render('nas/Index', [
            'devices' => array_map(static function (array $r) {
                $deployed = $r['applied_revision'] !== null && $r['applied_revision'] == $r['config_revision'];
                return [
                    'id' => (int) $r['id'],
                    'name' => (string) ($r['shortname'] ?: $r['nasname']),
                    'ip' => (string) $r['nasname'],
                    'vendor' => (string) ($r['vendor'] ?: $r['type']),
                    'enabled' => (bool) $r['enabled'],
                    'update' => $deployed ? Present::tag($r['pending_delete'] ? 'Removed' : 'Up to date', null) : Present::tag('Waiting for update', 'warning'),
                    'disconnect' => $deployed && $r['enabled'] ? Present::nasDisconnect($r) : null,
                    'online' => (int) $r['online_users'],
                    'description' => (string) $r['description'],
                    'pendingDelete' => (bool) $r['pending_delete'],
                ];
            }, $rows),
        ]);
    }

    public function health(): RedirectResponse
    {
        return redirect('/monitoring');
    }

    public function form(?string $id = null): Response
    {
        $stored = $id ? Db::row(self::rowSql() . ' WHERE n.id=?', [$id]) : null;
        abort_if($id && !$stored, 404, 'NAS device not found.');
        $vendor = strtolower((string) (($stored['vendor'] ?? '') ?: ($stored['type'] ?? '') ?: 'mikrotik'));
        $vendors = Nas::VENDORS;
        if (!isset($vendors[$vendor])) {
            // Rows saved with retired templates (pfSense/generic) stay editable until an operator picks a supported vendor.
            $vendors[$vendor] = ['label' => 'Other (' . $vendor . ')', 'summary' => 'Legacy template. Choose MikroTik or Cisco to enable a supported disconnect method.', 'disconnect' => 'none'];
        }
        $device = [
            'id' => $stored ? (int) $stored['id'] : '',
            'section' => 'device',
            'vendor' => $vendor,
            'shortname' => (string) ($stored['shortname'] ?? ''),
            'nasname' => (string) ($stored['nasname'] ?? ''),
            'secret' => '',
            'description' => (string) ($stored['description'] ?? ''),
            'enabled' => $stored ? (bool) ($stored['enabled'] ?? true) : true,
            // RADIUS disconnect (CoA) is signed with the shared secret above, so it lives on this tab.
            'coa_enabled' => $stored ? (int) ($stored['coa_enabled'] ?? 1) === 1 : true,
            'coa_port' => (string) (($stored['coa_port'] ?? '') ?: ($vendor === 'cisco' ? '1700' : '3799')),
        ];
        $props = [
            'device' => $device,
            'vendors' => $vendors,
            'setupSteps' => Nas::SETUP_STEPS,
            'canManage' => Auth::can('nas.manage'),
        ];
        if (!$stored) {
            return Inertia::render('nas/Form', $props + ['nas' => null]);
        }

        $nasId = (int) $stored['id'];
        $online = Db::select('SELECT username, framedipaddress, acctstarttime, acctsessiontime, acctupdatetime, acctstoptime FROM radacct WHERE acctstoptime IS NULL AND nasipaddress IN (?, ?) ORDER BY acctstarttime DESC', [$stored['nasname'], $stored['shortname']]);
        return Inertia::render('nas/Form', $props + [
            'nas' => [
                'id' => $nasId,
                'name' => (string) ($stored['shortname'] ?: $stored['nasname']),
                'ip' => (string) $stored['nasname'],
                'vendor' => $vendor,
                'vendorLabel' => $vendors[$vendor]['label'],
                'enabled' => (bool) $stored['enabled'],
                'description' => (string) $stored['description'],
                'stats' => $this->summary($stored, $vendor, count($online)),
            ],
            'api' => [
                'id' => $nasId,
                'section' => 'api',
                'api_enabled' => (bool) $stored['api_enabled'],
                'api_user' => (string) ($stored['api_user'] ?? ''),
                'api_password' => '',
                'apiPasswordSaved' => (bool) $stored['api_password_saved'],
            ],
            'snmp' => $this->snmp($stored, $vendor),
            'sessions' => array_map(static fn (array $x) => [
                'username' => (string) $x['username'],
                'ip' => (string) $x['framedipaddress'],
                'started' => (string) $x['acctstarttime'],
                'time' => Format::seconds((int) $x['acctsessiontime']),
                'state' => Accounting::state($x),
            ], $online),
        ]);
    }

    /** Identity and the four things an operator checks first. */
    private function summary(array $nas, string $vendor, int $sessions): array
    {
        $deployed = $nas['applied_revision'] !== null && $nas['applied_revision'] == $nas['config_revision'];
        $disconnect = RadiusRepository::disconnectMethod($nas);
        $snmp = NasMonitor::available() ? NasMonitor::settings((int) $nas['id']) : null;
        $snmpLabel = !NasMonitor::configured($snmp) ? 'Off' : ($snmp['version'] === '3'
            ? 'v3 · ' . (($snmp['v3_priv_protocol'] ?? 'none') !== 'none' ? 'authPriv' : (($snmp['v3_auth_protocol'] ?? 'none') !== 'none' ? 'authNoPriv' : 'noAuthNoPriv'))
            : 'v' . $snmp['version']);
        $stat = static fn (string $label, string $value, string $note, string $tone = '') => compact('label', 'value', 'note', 'tone');
        return [
            $stat('RADIUS update', $deployed ? 'Up to date' : 'Waiting', $deployed ? 'RADIUS is using these settings' : 'Saved changes start after the next RADIUS update', $deployed ? '' : 'warning'),
            $stat('Online now', (string) $sessions, 'From RADIUS accounting'),
            $stat('Disconnect', ucfirst((string) $disconnect['label']), $disconnect['note'], $disconnect['class'] === 'success' ? '' : 'warning'),
            $stat('SNMP', $snmpLabel, $snmpLabel === 'Off' ? 'Monitoring not set up' : 'Monitoring enabled', $snmpLabel === 'Off' ? 'info' : ''),
        ];
    }

    private function snmp(array $nas, string $vendor): ?array
    {
        if (!NasMonitor::available()) {
            return null;
        }
        $settings = NasMonitor::settings((int) $nas['id']) ?? [];
        $v3Ready = NasMonitor::v3Available();
        return [
            'values' => [
                'snmp_enabled' => !empty($settings['enabled']),
                'snmp_port' => (string) ($settings['port'] ?? '161'),
                'snmp_host' => (string) ($settings['host'] ?? ''),
                'snmp_version' => (string) ($settings['version'] ?? '2c'),
                'snmp_timeout_ms' => (string) ($settings['timeout_ms'] ?? '1500'),
                // The read-only community is shown to managers only, as before.
                'snmp_community' => Auth::can('nas.manage') ? Secrets::reveal((string) ($settings['community'] ?? '')) : '',
                'snmp_v3_user' => (string) ($settings['v3_user'] ?? ''),
                'snmp_v3_context' => (string) ($settings['v3_context'] ?? ''),
                'snmp_v3_auth_protocol' => (string) ($settings['v3_auth_protocol'] ?? 'sha1'),
                'snmp_v3_auth_password' => '',
                'snmp_v3_priv_protocol' => (string) ($settings['v3_priv_protocol'] ?? 'aes128'),
                'snmp_v3_priv_password' => '',
            ],
            'authSaved' => !empty($settings['v3_auth_password']),
            'privSaved' => !empty($settings['v3_priv_password']),
            'v3Ready' => $v3Ready,
            'versions' => ($v3Ready ? ['3' => 'SNMP v3 (most secure)'] : []) + ['2c' => 'SNMP v2c', '1' => 'SNMP v1'],
            'authOptions' => ['none' => 'None (noAuthNoPriv)', 'md5' => 'MD5', 'sha1' => 'SHA-1', 'sha224' => 'SHA-224', 'sha256' => 'SHA-256', 'sha384' => 'SHA-384', 'sha512' => 'SHA-512'],
            'privOptions' => ['none' => 'None (authNoPriv)', 'aes128' => 'AES-128'] + (Snmp::desAvailable() || ($settings['v3_priv_protocol'] ?? '') === 'des' ? ['des' => 'DES (legacy)'] : []),
            'routerHint' => match ($vendor) {
                'mikrotik' => 'RouterOS: /snmp set enabled=yes; /snmp community add name=<community> addresses=<this server>/32 read-access=yes write-access=no',
                'cisco' => 'IOS XE: snmp-server community <community> RO <acl>, with an ACL that permits only this server.',
                default => 'Allow read-only SNMP from this server only.',
            },
            'v3Hint' => match ($vendor) {
                'mikrotik' => 'RouterOS: /snmp community add name=<user> security=private authentication-protocol=SHA1 authentication-password=<auth> encryption-protocol=AES encryption-password=<priv> addresses=<this server>/32 read-access=yes write-access=no (the community name is the v3 user).',
                'cisco' => 'IOS XE: snmp-server group MONITOR v3 priv read <view> access <acl>; snmp-server user <user> MONITOR v3 auth sha <auth> priv aes 128 <priv>.',
                default => 'Create a read-only SNMPv3 user limited to this server.',
            },
        ];
    }

    public function save(Request $request): RedirectResponse
    {
        Auth::requireCan('nas.manage');
        $post = $this->formInput($request);
        $id = (int) ($post['id'] ?? 0) ?: null;
        $before = $id ? Db::row('SELECT * FROM nas WHERE id=?', [$id]) : null;
        $section = ($post['section'] ?? '') === 'disconnect' ? 'api' : (string) ($post['section'] ?? '');
        $section = in_array($section, ['device', 'api'], true) ? $section : 'device';
        $back = $id ? '/nas/' . $id . '/edit?tab=' . $section : '/nas/create';
        if ($id) {
            // Each tab posts only its own fields; everything else keeps its stored value.
            $stored = Db::row('SELECT n.*, m.vendor, m.enabled, m.coa_enabled, m.coa_port, m.api_user, ' . RadiusRepository::apiEnabledSql('m') . ' AS api_enabled FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE n.id=?', [$id]);
            if (!$stored) {
                return $this->fail('NAS device not found.', '/nas');
            }
            $post = self::tabPost($post, $stored, $section);
        }
        $post += ['ports' => '0', 'server' => '', 'community' => '', 'description' => '', 'coa_port' => '', 'api_user' => '', 'api_password' => '', 'coa_enabled' => '1', 'api_enabled' => ''];
        if (strlen((string) $post['api_password']) > Secrets::MAX_LENGTH) {
            return $this->failKeepingInput(new RuntimeException('API password must be at most ' . Secrets::MAX_LENGTH . ' characters.'), $back, ['api_password' => '/./']);
        }
        $vendor = strtolower(trim((string) ($post['vendor'] ?? '')));
        $storedVendor = $id ? strtolower((string) Db::value('SELECT COALESCE(m.vendor, n.type) FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE n.id=?', [$id])) : '';
        if (!isset(Nas::VENDORS[$vendor]) && ($vendor === '' || $vendor !== $storedVendor)) {
            return $this->failKeepingInput(new RuntimeException('Choose MikroTik RouterOS or Cisco IOS XE.'), $back, ['vendor' => '/./']);
        }
        $post['vendor'] = $vendor;
        $post['type'] = isset(Nas::VENDORS[$vendor]) ? $vendor : 'other';
        // The RouterOS API is always reached on the NAS IP (tls://<NAS IP>:8729).
        $post['api_host'] = '';
        if ($vendor === 'cisco') {
            // The RouterOS API only applies to MikroTik; Cisco disconnects use CoA/PoD.
            $post['api_user'] = '';
            $post['api_enabled'] = '';
        }
        if (trim((string) $post['coa_port']) === '') {
            $post['coa_port'] = $vendor === 'cisco' ? '1700' : '3799';
        }
        if (!empty($post['api_enabled']) && trim((string) $post['api_user']) === '') {
            return $this->failKeepingInput(new RuntimeException('Enter the API user (and its password) before turning the API on.'), $back, ['api_user' => '/./']);
        }
        try {
            $saved = RadiusRepository::saveNas($post, $id);
        } catch (Throwable $e) {
            return $this->failKeepingInput($e, $back, ['nasname' => '/\bIP\b|address|nasname/i', 'secret' => '/secret/i', 'shortname' => '/name/i']);
        }
        Audit::log($id ? 'update' : 'create', 'nas', (string) $saved, $before, array_diff_key($post, ['secret' => true, 'api_password' => true]));
        return $this->ok('/nas/' . $saved . '/edit' . ($id ? '?tab=' . $section : ''), $section === 'api'
            ? 'API settings saved. ' . (!empty($post['api_enabled']) ? 'Disconnects on this NAS now use the RouterOS API.' : 'The API is off; disconnects use RADIUS CoA when it is on.')
            : 'NAS saved. Changes take effect after the next RADIUS update.');
    }

    /**
     * Builds the full NAS save input from one tab's form: that tab's fields come from the post,
     * every other field keeps its stored value. Secrets stay blank, which keeps the saved ones.
     */
    private static function tabPost(array $post, array $stored, string $section): array
    {
        $keep = [
            'device' => ['vendor' => (string) ($stored['vendor'] ?: $stored['type']), 'shortname' => (string) $stored['shortname'], 'nasname' => (string) $stored['nasname'], 'description' => (string) $stored['description'], 'enabled' => (int) $stored['enabled'] === 1 ? '1' : '', 'secret' => '',
                'coa_enabled' => (int) $stored['coa_enabled'] === 1 ? '1' : '', 'coa_port' => (string) $stored['coa_port']],
            'api' => ['api_enabled' => !empty($stored['api_enabled']) ? '1' : '', 'api_user' => (string) $stored['api_user'], 'api_password' => ''],
        ];
        $posted = $keep[$section];
        foreach (array_keys($posted) as $key) {
            if (in_array($key, ['enabled', 'coa_enabled', 'api_enabled'], true)) {
                $posted[$key] = !empty($post[$key]) ? '1' : '';
            } elseif (array_key_exists($key, $post)) {
                $posted[$key] = (string) $post[$key];
            }
        }
        return array_merge($keep['device'], $keep['api'], $posted, ['ports' => (string) $stored['ports'], 'server' => (string) $stored['server'], 'community' => (string) $stored['community'], 'id' => (int) $stored['id']]);
    }

    public function delete(string $id): RedirectResponse
    {
        Auth::requireCan('danger.delete');
        $before = Db::row('SELECT * FROM nas WHERE id=?', [$id]);
        try {
            RadiusRepository::deleteNas((int) $id);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/nas');
        }
        Audit::log('delete', 'nas', $id, $before);
        return $this->ok('/nas', 'NAS removed. Its requests stop being accepted after the next RADIUS update.');
    }

    public function snmpSave(Request $request, string $id): RedirectResponse
    {
        Auth::requireCan('nas.manage');
        $nas = Nas::monitorRow($id);
        $input = $this->formInput($request);
        $before = NasMonitor::available() ? NasMonitor::settings((int) $nas['id']) : null;
        try {
            NasMonitor::save((int) $nas['id'], $input);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/nas/' . (int) $nas['id'] . '/edit?tab=snmp');
        }
        unset($before['community'], $before['v3_auth_password'], $before['v3_priv_password']);
        $after = array_intersect_key($input, array_flip(['snmp_enabled', 'snmp_host', 'snmp_port', 'snmp_version', 'snmp_timeout_ms', 'snmp_v3_user', 'snmp_v3_auth_protocol', 'snmp_v3_priv_protocol', 'snmp_v3_context']));
        $after['community_changed'] = ($input['snmp_community'] ?? '') !== '';
        $after['v3_passwords_changed'] = ($input['snmp_v3_auth_password'] ?? '') !== '' || ($input['snmp_v3_priv_password'] ?? '') !== '';
        Audit::log('update', 'nas_snmp', (string) $nas['id'], $before, $after);
        return $this->ok('/nas/' . (int) $nas['id'] . '/edit?tab=snmp', 'SNMP settings saved.');
    }
}
