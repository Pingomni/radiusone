<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** Ends one verified PPP session on its NAS: RouterOS API when it is switched on, otherwise RADIUS CoA. */
final class SessionDisconnect
{
    /** Exactly one accounting identity, optionally explicitly selected. No discovery fallback. */
    public static function disconnectUser(string $username, ?int $nasId = null, ?int $accountingId = null): array
    {
        $method = Db::value('SELECT auth_method FROM customer_profiles WHERE username=?', [$username]);
        if ($method === 'dhcp') {
            throw new RuntimeException('DHCP requires a validated forwarding/lease adapter; PPP removal is forbidden.');
        }
        $session = Db::row(
            'SELECT r.*, n.id AS nas_id, n.secret, n.nasname, n.type AS nas_type,
                    m.coa_enabled,m.coa_port,COALESCE(m.vendor,n.type) AS vendor,m.api_host,m.api_user,m.api_password,' . NasRepository::apiEnabledSql('m') . ' AS api_enabled,m.enabled,m.config_revision,m.applied_revision,m.pending_delete
             FROM radacct r JOIN nas n ON n.nasname=r.nasipaddress JOIN nas_metadata m ON m.nas_id=n.id
             WHERE ' . ClientQuery::inUsernames('r.username', $ids = ClientQuery::usernames($username)) . ' AND r.acctstoptime IS NULL AND (? IS NULL OR n.id=?) AND (? IS NULL OR r.radacctid=?)
             ORDER BY r.acctstarttime DESC,r.radacctid DESC LIMIT 1', array_merge($ids, [$nasId, $nasId, $accountingId, $accountingId]));
        if (!$session || empty($session['acctsessionid'])) {
            throw new RuntimeException('No identified active session found in this NAS scope.');
        }
        if (empty($session['enabled']) || !empty($session['pending_delete']) || $session['applied_revision'] === null || (int) $session['applied_revision'] !== (int) $session['config_revision']) {
            throw new RuntimeException('NAS configuration is disabled or pending deployment.');
        }
        $updated = strtotime((string) ($session['acctupdatetime'] ?: $session['acctstarttime']));
        if (!$updated || $updated < time() - Accounting::window($session) || $updated > time() + 60) {
            throw new RuntimeException('Accounting is stale; verify the session in a lab/operational workflow before disconnect.');
        }
        $method = self::disconnectMethod($session)['method'];
        if ($method === 'api') {
            $output = self::disconnectUserViaMikrotikApi($session, (string) $session['username']);
            if ($output === null) {
                throw new RuntimeException('MikroTik API credentials are required.');
            }
            return ['command' => 'mikrotik-api-tls', 'output' => $output];
        }
        if ($method !== 'coa') {
            throw new RuntimeException('No disconnect method is set up for this NAS: turn on RADIUS disconnect (CoA) on its Device tab, or the RouterOS API on its API tab.');
        }
        $attrs = ['User-Name' => $session['username'], 'Acct-Session-Id' => $session['acctsessionid'], 'NAS-IP-Address' => $session['nasipaddress']];
        $payload = '';
        foreach ($attrs as $attribute => $value) {
            if (preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
                throw new RuntimeException('Invalid disconnect identity.');
            }
            $payload .= $attribute . ' = "' . addcslashes((string) $value, '\\"') . '"' . "\n";
        }
        $payload .= "Message-Authenticator = 0x00\n";
        $dir = (string) App::env('SECURITY_STORAGE', App::root('storage/security'));
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Private secret storage unavailable.');
        }
        $file = tempnam($dir, 'disconnect-');
        if ($file === false) {
            throw new RuntimeException('Private secret file unavailable.');
        }
        try {
            chmod($file, 0600);
            if (file_put_contents($file, (string) $session['secret'] . "\n") === false) {
                throw new RuntimeException('Secret file write failed.');
            }
            $result = BoundedProcess::run([(string) App::env('RADCLIENT_BIN', '/usr/bin/radclient'), '-r', '1', '-t', '2', '-S', $file, $session['nasipaddress'] . ':' . (int) $session['coa_port'], 'disconnect'], $payload, 8);
            if ($result['exit'] !== 0 || !preg_match('/\bDisconnect-ACK\b/', $result['output'])) {
                throw new RuntimeException('Disconnect not acknowledged; verify the same session before retrying.');
            }
            return ['command' => 'radclient-disconnect', 'output' => 'The NAS acknowledged the disconnect (Disconnect-ACK).'];
        } finally {
            unlink($file);
        }
    }

    /**
     * RouterOS 7 lists a PPP session as "0x81000000" while its accounting carries Acct-Session-Id
     * "81000000": the same session. Compare without the prefix and case; an empty id never matches.
     */
    private static function sameSessionId(string $router, string $accounting): bool
    {
        $norm = static fn (string $id): string => strtolower(preg_replace('/^0x/i', '', trim($id)) ?? '');
        return $norm($router) !== '' && $norm($router) === $norm($accounting);
    }

    /**
     * How the portal ends a session on this NAS: the RouterOS API when it is switched on (MikroTik
     * only) and has a login, otherwise a RADIUS Disconnect-Request (CoA) signed with the shared
     * secret, like most RADIUS managers. Takes a NAS row; secret may be named secret or nas_secret.
     *
     * @return array{method:?string,label:string,ok:bool,class:string,note:string}
     */
    public static function disconnectMethod(array $nas): array
    {
        $vendor = strtolower(trim((string) (($nas['vendor'] ?? '') ?: ($nas['nas_vendor'] ?? '') ?: ($nas['nas_type'] ?? '') ?: ($nas['type'] ?? ''))));
        if ($vendor === 'mikrotik' && !empty($nas['api_enabled'])) {
            return self::mikrotikDisconnectApiStatus($nas)['ok']
                ? ['method' => 'api', 'label' => 'API ready', 'ok' => true, 'class' => 'success', 'note' => 'RouterOS API']
                : ['method' => null, 'label' => 'API login missing', 'ok' => false, 'class' => 'warning', 'note' => 'RouterOS API'];
        }
        $secret = (string) (($nas['secret'] ?? '') ?: ($nas['nas_secret'] ?? ''));
        $hasSecret = $secret !== '' || !empty($nas['secret_saved']);
        if ((int) ($nas['coa_enabled'] ?? 0) === 1 && $hasSecret) {
            return ['method' => 'coa', 'label' => 'CoA ready', 'ok' => true, 'class' => 'success', 'note' => 'RADIUS disconnect (CoA) on UDP ' . (int) (($nas['coa_port'] ?? 0) ?: 3799)];
        }
        return ['method' => null, 'label' => 'off', 'ok' => false, 'class' => 'warning', 'note' => 'Turn on CoA (Device tab) or the API'];
    }

    public static function mikrotikDisconnectApiStatus(array $nas): array
    {
        $host = trim((string) (($nas['api_host'] ?? '') ?: ($nas['nasname'] ?? '')));
        $user = trim((string) ($nas['api_user'] ?? ''));
        $passwordSaved = !empty($nas['api_password']) || !empty($nas['api_password_saved']);
        if ($host === '' || $user === '' || !$passwordSaved) {
            return ['ok' => false, 'label' => 'not configured', 'class' => 'warning'];
        }

        return ['ok' => true, 'label' => 'configured', 'class' => 'success'];
    }

    private static function disconnectUserViaMikrotikApi(array $session, string $username): ?string
    {
        $host = trim((string) ($session['api_host'] ?: $session['nasname'] ?: $session['nasipaddress'] ?: ''));
        $user = trim((string) ($session['api_user'] ?? ''));
        $password = Secrets::reveal($session['api_password'] ?? '');
        if ($host === '' || $user === '' || $password === '') {
            return null;
        }

        $socket = RouterOsApi::connect($host);

        try {
            RouterOsApi::writeSentence($socket, ['/login', '=name=' . $user, '=password=' . $password]);
            RouterOsApi::assertOk(RouterOsApi::readReply($socket), 'MikroTik API login failed');

            $rows = RouterOsApi::findActivePpp($socket, 'name', $username);
            $matches = array_values(array_filter($rows, function (array $row) use ($session, $username): bool {
                return ($row['name'] ?? '') === $username && self::sameSessionId((string) ($row['session-id'] ?? ''), (string) $session['acctsessionid'])
                    && !empty($row['.id']) && (empty($session['callingstationid']) || ($row['caller-id'] ?? '') === $session['callingstationid']);
            }));
            if (count($matches) !== 1) {
                throw new RuntimeException('NAS did not verify exactly one matching username/session identity.');
            }
            // Re-read that handle immediately before removal. A reused address never supplies identity.
            $verified = RouterOsApi::findActivePpp($socket, '.id', (string) $matches[0]['.id']);
            if (count($verified) !== 1 || ($verified[0]['name'] ?? '') !== $username || !self::sameSessionId((string) ($verified[0]['session-id'] ?? ''), (string) $session['acctsessionid'])) {
                throw new RuntimeException('NAS session changed before removal.');
            }
            RouterOsApi::writeSentence($socket, ['/ppp/active/remove', '=.id=' . $matches[0]['.id']]);
            RouterOsApi::assertOk(RouterOsApi::readReply($socket), 'MikroTik API remove failed');
            return 'MikroTik API removed one verified PPP session; awaiting accounting reconciliation.';
        } finally {
            fclose($socket);
        }
    }
}
