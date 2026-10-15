<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Operator authentication on portal_users, stored in the Laravel session:
 * user_id + session_version (bumped to revoke all sessions), pending 2FA states, and reauth_at
 * for actions that need a recent password (+ code) confirmation.
 */
final class Auth
{
    public const ROLES = ['superadmin', 'admin', 'view'];
    private const USER_ATTRIBUTE = 'frweb.user';

    public static function user(): ?array
    {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return null;
        }
        $request = request();
        if ($request->attributes->has(self::USER_ATTRIBUTE)) {
            return $request->attributes->get(self::USER_ATTRIBUTE);
        }
        $session = session();
        $user = null;
        if ($session->get('user_id')) {
            $user = Db::row('SELECT * FROM portal_users WHERE id=? AND enabled=1', [$session->get('user_id')]);
            if (!$user || (int) $session->get('session_version', 0) !== (int) $user['session_version']) {
                $session->forget(['user_id', 'session_version', 'reauth_at', 'profile_2fa_secret']);
                $user = null;
            }
        }
        $request->attributes->set(self::USER_ATTRIBUTE, $user);
        return $user;
    }

    /** Drops the per-request user cache after the session or the user row changed. */
    public static function refresh(): void
    {
        request()->attributes->remove(self::USER_ATTRIBUTE);
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function attempt(string $username, string $password): bool
    {
        $key = 'login:' . hash_hmac('sha256', Security::clientIp() . '|' . strtolower(trim($username)), (string) config('app.key'));
        $max = max(1, (int) App::env('LOGIN_MAX_ATTEMPTS', 5));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        $user = Db::row('SELECT * FROM portal_users WHERE username = ? AND enabled = 1', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::hit($key, max(60, (int) App::env('LOGIN_DECAY_MINUTES', 15) * 60));
            return false;
        }

        RateLimiter::clear($key);
        $session = session();
        $session->invalidate();
        $session->put('pending_session_version', (int) $user['session_version']);
        if (!empty($user['two_factor_enabled'])) {
            $session->put(['pending_2fa_user_id' => (int) $user['id'], 'pending_2fa_since' => time()]);
            return true;
        }
        if (in_array($user['role'], ['superadmin', 'admin', 'noc', 'billing', 'security'], true) && empty($user['two_factor_secret'])) {
            $user['two_factor_secret'] = Security::twoFactorSecret();
            Db::exec('UPDATE portal_users SET two_factor_secret=? WHERE id=? AND (two_factor_secret IS NULL OR two_factor_secret="")', [$user['two_factor_secret'], $user['id']]);
        }
        if (!empty($user['two_factor_secret'])) {
            $session->put(['pending_2fa_setup_user_id' => (int) $user['id'], 'pending_2fa_setup_since' => time()]);
            return true;
        }

        self::establish($user);
        Audit::log('login', 'portal_user', (string) $user['id'], null, ['username' => $user['username']]);
        return true;
    }

    public static function twoFactorPending(): bool
    {
        return (bool) session('pending_2fa_user_id') && (int) session('pending_2fa_since', 0) >= time() - 600;
    }

    public static function pendingTwoFactorUser(): ?array
    {
        if (!self::twoFactorPending()) {
            session()->forget(['pending_2fa_user_id', 'pending_2fa_since']);
            return null;
        }
        return Db::row('SELECT * FROM portal_users WHERE id = ? AND enabled = 1 AND two_factor_enabled = 1', [session('pending_2fa_user_id')]);
    }

    public static function twoFactorSetupPending(): bool
    {
        return (bool) session('pending_2fa_setup_user_id') && (int) session('pending_2fa_setup_since', 0) >= time() - 600;
    }

    public static function pendingTwoFactorSetupUser(): ?array
    {
        if (!self::twoFactorSetupPending()) {
            session()->forget(['pending_2fa_setup_user_id', 'pending_2fa_setup_since']);
            return null;
        }
        return Db::row('SELECT * FROM portal_users WHERE id = ? AND enabled = 1 AND two_factor_enabled = 0 AND two_factor_secret IS NOT NULL AND two_factor_secret <> ""', [session('pending_2fa_setup_user_id')]);
    }

    public static function completeTwoFactor(string $code): bool
    {
        $user = self::pendingTwoFactorUser();
        if (!$user || (int) session('pending_session_version', 0) !== (int) $user['session_version'] || !self::consumeTotp($user, (string) $user['two_factor_secret'], $code)) {
            return false;
        }
        session()->regenerate(true);
        session()->forget(['pending_2fa_user_id', 'pending_2fa_since']);
        self::establish($user);
        Audit::log('login', 'portal_user', (string) $user['id'], null, ['username' => $user['username'], 'two_factor' => true]);
        return true;
    }

    public static function completeTwoFactorSetup(string $code): bool
    {
        $user = self::pendingTwoFactorSetupUser();
        if (!$user || (int) session('pending_session_version', 0) !== (int) $user['session_version'] || !self::consumeTotp($user, (string) $user['two_factor_secret'], $code)) {
            return false;
        }
        if (Db::exec('UPDATE portal_users SET two_factor_enabled=1, session_version=session_version+1, updated_at=NOW() WHERE id=? AND session_version=? AND two_factor_secret=? AND two_factor_enabled=0', [$user['id'], $user['session_version'], $user['two_factor_secret']]) !== 1) {
            return false;
        }
        $user['session_version']++;
        session()->regenerate(true);
        session()->forget(['pending_2fa_setup_user_id', 'pending_2fa_setup_since']);
        self::establish($user);
        Audit::log('enable_2fa_login_setup', 'portal_user', (string) $user['id'], null, ['username' => $user['username']]);
        return true;
    }

    /** Marks the session signed in as $user (after password and, when required, a code). */
    private static function establish(array $user): void
    {
        session()->put([
            'user_id' => (int) $user['id'],
            'session_version' => (int) $user['session_version'],
            'reauth_at' => time(),
        ]);
        session()->forget('pending_session_version');
        Db::exec('UPDATE portal_users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?', [$user['id']]);
        self::refresh();
    }

    /** Serialize attempts and consume a counter once across all sessions. */
    public static function consumeTotp(array $expected, string $secret, string $code): bool
    {
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $user = Db::row('SELECT * FROM portal_users WHERE id=? AND enabled=1 FOR UPDATE', [$expected['id']]);
            if (!$user || (int)$user['session_version'] !== (int)$expected['session_version'] || (int)$user['mfa_locked_until'] > time()) { $pdo->commit(); return false; }
            $counter = Security::totpCounter($secret, $code);
            if ($counter === null || ($user['mfa_last_counter'] !== null && $counter <= (int)$user['mfa_last_counter'])) {
                self::failChallenge($user);
                $pdo->commit(); return false;
            }
            Db::exec('UPDATE portal_users SET mfa_attempts=0,mfa_locked_until=0,mfa_last_counter=? WHERE id=?', [$counter,$user['id']]);
            $pdo->commit(); return true;
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }

    private static function failChallenge(array $user): void
    {
        $attempts = (int)$user['mfa_locked_until'] > 0 && (int)$user['mfa_locked_until'] <= time() ? 1 : (int)$user['mfa_attempts'] + 1;
        Db::exec('UPDATE portal_users SET mfa_attempts=?,mfa_locked_until=? WHERE id=?', [$attempts, $attempts >= 5 ? time()+900 : 0,$user['id']]);
    }

    public static function reauthenticate(string $password, string $code): bool
    {
        $user = self::user();
        if (!$user || (int) $user['mfa_locked_until'] > time()) {
            return false;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $locked = Db::row('SELECT * FROM portal_users WHERE id=? FOR UPDATE', [$user['id']]);
                self::failChallenge($locked);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            return false;
        }
        if (!empty($user['two_factor_enabled']) && !self::consumeTotp($user, (string) $user['two_factor_secret'], $code)) {
            return false;
        }
        if (empty($user['two_factor_enabled'])) {
            Db::exec('UPDATE portal_users SET mfa_attempts=0,mfa_locked_until=0 WHERE id=?', [$user['id']]);
        }
        session()->regenerate();
        session()->put('reauth_at', time());
        return true;
    }

    public static function recentlyAuthenticated(): bool
    {
        return self::user() !== null && (int) session('reauth_at', 0) >= time() - 300;
    }

    public static function internalReturnPath(string $path, string $fallback = '/profile'): string
    {
        if (strlen($path) > 2048 || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) { return $fallback; }
        $decoded = $path;
        for ($i = 0; $i < 3; $i++) {
            if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) || str_starts_with($decoded, '//')) { return $fallback; }
            $decoded = rawurldecode($decoded);
        }
        $parts = parse_url($path);
        if ($parts && preg_match('#(?:^|/)\.{1,2}(?:/|$)#', rawurldecode(rawurldecode((string)($parts['path'] ?? ''))))) { return $fallback; }
        if (!$parts || isset($parts['host']) || isset($parts['scheme']) || !preg_match('#^/(?:profile(?:/2fa/setup)?|settings(?:/users(?:/create|/\d+/edit)?|/notifications/(?:general|sms|email))?|portal-users(?:/create|/\d+/edit)?|nas(?:/create|/\d+/edit)?|clients(?:/create|/[^/]+/edit)?|billing(?:/invoices/(?:create|\d+(?:/edit)?))?)?$#D', $parts['path'] ?? '')) { return $fallback; }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/password|secret|token|csrf|(?:^|_)code(?:$|_)/i', $key)) { unset($query[$key]); }
        }
        return $parts['path'] . ($query ? '?' . http_build_query($query) : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function reauthReturnPath(): string
    {
        $pending = session('reauth_return', []);
        if (($pending['user_id'] ?? null) !== session('user_id')
            || ($pending['version'] ?? null) !== session('session_version')
            || ($pending['expires'] ?? 0) < time()) {
            return '/profile';
        }
        return self::internalReturnPath((string) ($pending['path'] ?? ''));
    }

    /** Remembers where to go after /reauth. Callers redirect to /reauth themselves. */
    public static function rememberReauthReturn(string $returnPath): void
    {
        session()->put('reauth_return', [
            'path' => self::internalReturnPath($returnPath),
            'user_id' => session('user_id'),
            'version' => session('session_version'),
            'expires' => time() + 600,
        ]);
    }

    public static function revealClientPassword(string $username): string
    {
        if (!self::can('clients.manage') || !self::recentlyAuthenticated()) {
            throw new \RuntimeException('Password reveal requires management permission and recent reauthentication.');
        }
        Audit::log('reveal_password', 'client', $username);
        return (string) (Db::value('SELECT value FROM radcheck WHERE username=? AND attribute="Cleartext-Password" ORDER BY id DESC LIMIT 1', [$username]) ?: '');
    }

    public static function logout(): void
    {
        if ($user = self::user()) {
            Audit::log('logout', 'portal_user', (string) $user['id']);
        }
        session()->invalidate();
        session()->regenerateToken();
        self::refresh();
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }

        $role = $user['role'];
        if ($role === 'superadmin') {
            return true;
        }

        $matrix = [
            'support'=>['dashboard.view','clients.view','services.view','nas.view','reports.view','operations.view'],
            'noc'=>['dashboard.view','clients.view','clients.manage','clients.disconnect','clients.suspend','services.view','services.manage','nas.view','nas.manage','reports.view','logs.view','operations.view','operations.manage'],
            'billing'=>['dashboard.view','clients.view','services.view','billing.view','billing.manage','documents.view'],
            'security'=>['dashboard.view','clients.view','clients.suspend','clients.disconnect','nas.view','logs.view','operations.view','operations.manage','security.manage'],
            'admin' => [
                'dashboard.view', 'clients.view', 'clients.manage', 'clients.disconnect', 'clients.suspend', 'operations.view', 'operations.manage',
                'services.view', 'services.manage', 'nas.view', 'nas.manage',
                'billing.view', 'billing.manage', 'reports.view', 'logs.view', 'documents.manage', 'documents.view',
            ],
            'view' => [
                'dashboard.view', 'clients.view', 'services.view', 'nas.view',
                'billing.view', 'reports.view', 'logs.view',
            ],
        ];

        return in_array($permission, $matrix[$role] ?? [], true);
    }

    public static function requireCan(string $permission): void
    {
        if (!self::can($permission)) {
            abort(403, 'Your role does not include this action. Ask a workspace administrator if you need access.');
        }
    }

    /** Permissions of the current user, for the frontend. */
    public static function permissions(): array
    {
        $all = ['dashboard.view', 'clients.view', 'clients.manage', 'clients.disconnect', 'clients.suspend', 'operations.view', 'operations.manage',
            'services.view', 'services.manage', 'nas.view', 'nas.manage', 'billing.view', 'billing.manage', 'reports.view', 'logs.view',
            'documents.manage', 'documents.view', 'security.manage', 'settings.manage', 'portal_users.manage', 'danger.delete'];
        return array_values(array_filter($all, static fn (string $p) => self::can($p)));
    }
}
