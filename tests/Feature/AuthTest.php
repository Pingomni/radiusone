<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Auth;
use App\Support\Db;
use App\Support\Security;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/clients')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_plain_http_is_redirected_to_the_canonical_https_url(): void
    {
        config(['app.url' => 'https://frweb.test', 'app.force_https' => true]);
        $this->withHeader('X-Forwarded-Proto', 'http')->get('/login')->assertRedirect('https://frweb.test/login');
        $this->withHeader('X-Forwarded-Proto', 'http')->post('/login')->assertStatus(400);
    }

    public function test_view_role_without_two_factor_signs_in_directly(): void
    {
        $user = $this->createOperator('view', ['username' => 'viewer', 'password' => 'secret-pass-123']);
        $this->post('/login', ['username' => 'viewer', 'password' => 'secret-pass-123'])->assertRedirect('/');
        $this->assertSame((int) $user['id'], session('user_id'));
        $this->assertNotNull(Db::value('SELECT last_login_at FROM portal_users WHERE id=?', [$user['id']]));
        $this->assertSame('login', Db::value('SELECT action FROM audit_logs WHERE entity_id=? ORDER BY id DESC LIMIT 1', [(string) $user['id']]));
    }

    public function test_wrong_password_is_rejected_and_rate_limited(): void
    {
        $this->createOperator('view', ['username' => 'viewer', 'password' => 'right-password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'viewer', 'password' => 'wrong'])->assertRedirect('/login');
        }
        // Locked: even the right password is refused until the decay window passes.
        $this->post('/login', ['username' => 'viewer', 'password' => 'right-password'])->assertRedirect('/login');
        $this->assertNull(session('user_id'));
        $this->assertSame('danger', session('flash')['type']);
    }

    public function test_admin_must_enrol_an_authenticator_before_signing_in(): void
    {
        $user = $this->createOperator('admin', ['username' => 'boss', 'password' => 'admin-pass-123']);
        $this->post('/login', ['username' => 'boss', 'password' => 'admin-pass-123'])->assertRedirect('/two-factor/setup');
        $this->assertNull(session('user_id'));
        $secret = (string) Db::value('SELECT two_factor_secret FROM portal_users WHERE id=?', [$user['id']]);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $this->get('/two-factor/setup')->assertOk()->assertInertia(fn ($page) => $page
            ->component('auth/TwoFactor')
            ->where('setup.secret', $secret));

        $this->post('/two-factor/setup', ['code' => '000000'])->assertRedirect('/two-factor/setup');
        $this->post('/two-factor/setup', ['code' => $this->totp($secret)])->assertRedirect('/');
        $row = Db::row('SELECT * FROM portal_users WHERE id=?', [$user['id']]);
        $this->assertSame(1, (int) $row['two_factor_enabled']);
        $this->assertSame((int) $row['session_version'], session('session_version'));
        $this->assertSame((int) $user['id'], session('user_id'));
    }

    public function test_two_factor_challenge_rejects_replayed_codes(): void
    {
        $secret = Security::twoFactorSecret();
        $user = $this->createOperator('admin', ['username' => 'mfa', 'password' => 'mfa-pass-123', 'two_factor_secret' => $secret, 'two_factor_enabled' => 1]);
        $this->post('/login', ['username' => 'mfa', 'password' => 'mfa-pass-123'])->assertRedirect('/two-factor');
        $code = $this->totp($secret);
        $this->post('/two-factor', ['code' => $code])->assertRedirect('/');
        $this->assertSame((int) $user['id'], session('user_id'));

        // Same code in a new sign-in is refused (counter already consumed).
        $this->post('/logout');
        $this->post('/login', ['username' => 'mfa', 'password' => 'mfa-pass-123'])->assertRedirect('/two-factor');
        $this->post('/two-factor', ['code' => $code])->assertRedirect('/two-factor');
        $this->assertNull(session('user_id'));
    }

    public function test_bumping_session_version_signs_out_existing_sessions(): void
    {
        $user = $this->actingAsOperator('view');
        $this->get('/')->assertOk();
        Db::exec('UPDATE portal_users SET session_version=session_version+1 WHERE id=?', [$user['id']]);
        $this->get('/')->assertRedirect('/login');
    }

    public function test_logout_clears_the_session(): void
    {
        $this->actingAsOperator('view');
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_roles_without_permission_get_403(): void
    {
        $this->actingAsOperator('view');
        $this->get('/settings')->assertForbidden();
        $this->get('/settings/users')->assertForbidden();
        $this->post('/clients/save')->assertForbidden();
    }

    public function test_signed_in_operators_are_not_asked_for_the_password_again(): void
    {
        $user = $this->createOperator('superadmin', ['password' => 'super-pass-123']);
        // Signed in an hour ago: sensitive pages open without a new password prompt.
        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time() - 3600]);
        $this->get('/profile/2fa/setup')->assertOk();

        // The confirmation page still checks the password if someone opens it directly.
        $this->post('/reauth', ['password' => 'nope'])->assertRedirect('/reauth')->assertSessionHasErrors('password');
    }

    public function test_reauth_return_path_never_leaves_the_site(): void
    {
        $this->assertSame('/profile', Auth::internalReturnPath('//evil.example/x'));
        $this->assertSame('/profile', Auth::internalReturnPath('https://evil.example/'));
        $this->assertSame('/nas/5/edit', Auth::internalReturnPath('/nas/5/edit?password=x'));
    }
}
