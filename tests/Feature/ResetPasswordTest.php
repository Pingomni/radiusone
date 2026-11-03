<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use Tests\TestCase;

final class ResetPasswordTest extends TestCase
{
    private const OLD = 'old-password-1';
    private const NEW = 'new-password-1';

    private function operator(string $username = 'boss', int $enabled = 1): void
    {
        Db::exec(
            'INSERT INTO portal_users (name,email,username,password_hash,role,enabled,two_factor_secret,two_factor_enabled,mfa_attempts,mfa_locked_until,mfa_last_counter,created_at,updated_at)
             VALUES (?,?,?,?,"superadmin",?,"OLDSECRET",1,5,?,123,NOW(),NOW())',
            [$username, $username . '@example.com', $username, password_hash(self::OLD, PASSWORD_DEFAULT), $enabled, time() + 900],
        );
    }

    private function row(string $username = 'boss'): array
    {
        return Db::row('SELECT * FROM portal_users WHERE username=?', [$username]);
    }

    public function test_without_a_username_it_lists_the_logins_and_changes_nothing(): void
    {
        $this->operator();
        $before = $this->row();
        $this->artisan('radiusone:reset-password')->expectsOutputToContain('boss')->assertExitCode(1);
        $this->assertSame($before, $this->row());
    }

    public function test_an_unknown_login_is_refused(): void
    {
        $this->operator();
        $this->artisan('radiusone:reset-password', ['username' => 'nobody'])
            ->expectsOutputToContain('There is no login called nobody')->assertExitCode(1);
    }

    public function test_a_bad_password_changes_nothing(): void
    {
        $this->operator();
        $before = $this->row();
        $this->artisan('radiusone:reset-password', ['username' => 'boss'])
            ->expectsQuestion('New password (at least 10 characters)', 'short')
            ->expectsOutputToContain('at least 10 characters')->assertExitCode(1);
        $this->artisan('radiusone:reset-password', ['username' => 'boss'])
            ->expectsQuestion('New password (at least 10 characters)', self::NEW)
            ->expectsQuestion('Type it again', 'something-else')
            ->expectsOutputToContain('do not match')->assertExitCode(1);
        $this->artisan('radiusone:reset-password', ['username' => 'boss'])
            ->expectsQuestion('New password (at least 10 characters)', self::OLD)
            ->expectsQuestion('Type it again', self::OLD)
            ->expectsOutputToContain('already the password')->assertExitCode(1);
        $this->assertSame($before, $this->row());
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE action="reset_password"'));
    }

    public function test_it_changes_the_password_ends_sessions_and_keeps_the_authenticator(): void
    {
        $this->operator();
        $before = $this->row();
        $this->artisan('radiusone:reset-password', ['username' => 'boss'])
            ->expectsQuestion('New password (at least 10 characters)', self::NEW)
            ->expectsQuestion('Type it again', self::NEW)
            ->expectsOutputToContain('The authenticator code is still needed')->assertExitCode(0);

        $after = $this->row();
        $this->assertTrue(password_verify(self::NEW, $after['password_hash']));
        $this->assertFalse(password_verify(self::OLD, $after['password_hash']));
        $this->assertSame((int) $before['session_version'] + 1, (int) $after['session_version']);
        $this->assertSame('OLDSECRET', $after['two_factor_secret']);
        $this->assertSame(1, (int) $after['two_factor_enabled']);
        // The lockout from wrong codes ends, so the operator can sign in straight away.
        $this->assertSame(0, (int) $after['mfa_attempts']);
        $this->assertSame(0, (int) $after['mfa_locked_until']);
    }

    public function test_the_audit_log_records_the_reset_but_never_the_password(): void
    {
        $this->operator();
        $this->artisan('radiusone:reset-password', ['username' => 'boss'])
            ->expectsQuestion('New password (at least 10 characters)', self::NEW)
            ->expectsQuestion('Type it again', self::NEW)->assertExitCode(0);

        $audit = Db::row('SELECT * FROM audit_logs WHERE action="reset_password"');
        $this->assertSame((string) $this->row()['id'], $audit['entity_id']);
        $this->assertSame('cli', $audit['ip_address']);
        $details = json_decode($audit['after_json'], true);
        $this->assertSame('terminal', $details['via']);
        $this->assertFalse($details['two_factor_reset']);
        $this->assertNotEmpty($details['server_user']);
        $this->assertNull($audit['before_json']);
        $this->assertStringNotContainsString(self::NEW, json_encode($audit));
        $this->assertStringNotContainsString($this->row()['password_hash'], json_encode($audit));
    }

    public function test_reset_2fa_makes_the_login_set_up_a_new_authenticator(): void
    {
        $this->operator();
        $this->artisan('radiusone:reset-password', ['username' => 'boss', '--reset-2fa' => true])
            ->expectsQuestion('New password (at least 10 characters)', self::NEW)
            ->expectsQuestion('Type it again', self::NEW)
            ->expectsOutputToContain('sets up a new authenticator')->assertExitCode(0);

        $after = $this->row();
        $this->assertNotSame('OLDSECRET', $after['two_factor_secret']);
        $this->assertNotEmpty($after['two_factor_secret']);
        $this->assertSame(0, (int) $after['two_factor_enabled']);
        $this->assertNull($after['mfa_last_counter']);
        $this->assertTrue((bool) json_decode(Db::value('SELECT after_json FROM audit_logs WHERE action="reset_password"'), true)['two_factor_reset']);

        // The real login page takes the new password and goes to the authenticator setup, not into the portal.
        $this->post('/login', ['username' => 'boss', 'password' => self::NEW]);
        $this->assertSame((int) $after['id'], (int) session('pending_2fa_setup_user_id'));
    }

    public function test_only_the_named_login_changes_and_a_disabled_one_gets_a_warning(): void
    {
        $this->operator();
        $this->operator('asleep', 0);
        $other = $this->row();
        $this->artisan('radiusone:reset-password', ['username' => 'asleep'])
            ->expectsQuestion('New password (at least 10 characters)', self::NEW)
            ->expectsQuestion('Type it again', self::NEW)
            ->expectsOutputToContain('This login is disabled')->assertExitCode(0);
        $this->assertSame($other, $this->row());
        $this->assertSame(0, (int) $this->row('asleep')['enabled']);
    }
}
