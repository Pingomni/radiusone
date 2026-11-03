<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\Db;
use App\Support\Security;
use RuntimeException;
use Throwable;

/**
 * For an operator locked out of the portal. The password is only ever typed at the prompt, never an argument, so it
 * stays out of the shell history and the process list. On a server: sudo radiusone-root-artisan radiusone:reset-password
 */
final class ResetPassword extends RadiusOneCommand
{
    protected $signature = 'radiusone:reset-password
        {username? : The login to change. Leave it out to list the logins}
        {--reset-2fa : Also make this login set up a new authenticator app, for a lost phone}';

    protected $description = 'Set a new portal password for an operator, from the terminal';

    public function handle(): int
    {
        try {
            $username = $this->argument('username');
            if (!is_string($username) || $username === '') {
                $this->listOperators();
                $this->line('Run again with the login to change: radiusone:reset-password <username>');
                return self::FAILURE;
            }
            $user = Db::row('SELECT id,username,role,enabled,password_hash,two_factor_enabled,session_version FROM portal_users WHERE username=?', [$username]);
            if (!$user) {
                $this->listOperators();
                throw new RuntimeException("There is no login called $username.");
            }

            $this->line("New password for {$user['username']} ({$user['role']}). It won't show as you type.");
            $password = (string) $this->secret('New password (at least 10 characters)');
            // The same rules as changing it in the portal.
            if (strlen($password) < 10) {
                throw new RuntimeException('The password must be at least 10 characters. Nothing was changed.');
            }
            if ($password !== (string) $this->secret('Type it again')) {
                throw new RuntimeException('The two passwords do not match. Nothing was changed.');
            }
            if (password_verify($password, (string) $user['password_hash'])) {
                throw new RuntimeException('That is already the password for this login. Nothing was changed.');
            }

            $this->save($user, $password, (bool) $this->option('reset-2fa'));
            $this->report($user, (bool) $this->option('reset-2fa'));
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Password reset failed', $e, $e instanceof RuntimeException);
        }
    }

    /**
     * The change and its audit entry together, so a password never changes without a record.
     *
     * @param  array<string, mixed>  $user
     */
    private function save(array $user, string $password, bool $resetTwoFactor): void
    {
        // Open sessions end, and a lockout from wrong authenticator codes no longer applies.
        $sql = 'UPDATE portal_users SET password_hash=?,session_version=session_version+1,mfa_attempts=0,mfa_locked_until=0,updated_at=NOW()';
        $params = [password_hash($password, PASSWORD_DEFAULT)];
        if ($resetTwoFactor) {
            $sql .= ',two_factor_secret=?,two_factor_enabled=0,mfa_last_counter=NULL';
            $params[] = Security::twoFactorSecret();
        }
        $params[] = $user['id'];
        $params[] = $user['session_version'];

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if (Db::exec($sql . ' WHERE id=? AND session_version=?', $params) !== 1) {
                throw new RuntimeException('This login changed while you were typing. Nothing was changed: run it again.');
            }
            Audit::log('reset_password', 'portal_user', (string) $user['id'], null, [
                'username' => $user['username'],
                'via' => 'terminal',
                'server_user' => getenv('SUDO_USER') ?: (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown'),
                'two_factor_reset' => $resetTwoFactor,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $user */
    private function report(array $user, bool $resetTwoFactor): void
    {
        $this->info("Password changed for {$user['username']}. Any open sessions were logged out.");
        if ($resetTwoFactor) {
            $this->line('The next login sets up a new authenticator app. The old one no longer works.');
        } elseif (!empty($user['two_factor_enabled'])) {
            $this->line('The authenticator code is still needed. If the phone is lost, run this again with --reset-2fa.');
        }
        if (empty($user['enabled'])) {
            $this->warn('This login is disabled, so it still cannot sign in. Enable it under Settings → Users.');
        }
        $this->line('If the login page says there were too many attempts, wait 15 minutes and try again.');
    }

    private function listOperators(): void
    {
        $this->table(['Login', 'Role', 'Enabled', 'Authenticator'], array_map(
            static fn (array $u) => [$u['username'], $u['role'], $u['enabled'] ? 'yes' : 'no', $u['two_factor_enabled'] ? 'on' : 'off'],
            Db::select('SELECT username,role,enabled,two_factor_enabled FROM portal_users ORDER BY username'),
        ));
    }
}
