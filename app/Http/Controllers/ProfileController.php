<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Operators;
use App\Http\Support\Present;
use App\Support\App;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in operator's profile, password and two-factor settings. */
final class ProfileController extends Controller
{
    public function profile(Request $request): Response
    {
        $user = Auth::user();
        $pending = empty($user['two_factor_enabled']) && (!empty($user['two_factor_secret']) || $request->session()->has('profile_2fa_secret'));
        return Inertia::render('profile/Profile', [
            'profile' => [
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'username' => (string) $user['username'],
                'role' => (string) $user['role'],
                'enabled' => !empty($user['enabled']),
                'lastLogin' => (string) (($user['last_login_at'] ?? '') ?: '-'),
            ],
            'twoFactor' => [
                'state' => !empty($user['two_factor_enabled']) ? 'enabled' : ($pending ? 'pending' : 'off'),
                'tag' => $pending && empty($user['two_factor_secret']) ? Present::tag('setup required', 'warning') : Operators::twoFactorTag($user),
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        if ($name === '' || strlen($name) > 120) {
            return $this->fail('Enter a valid name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return $this->fail('Enter a valid email address.');
        }
        $before = Db::row('SELECT id, name, email, username, role, enabled, last_login_at FROM portal_users WHERE id=?', [$user['id']]);
        Db::exec('UPDATE portal_users SET name=?, email=?, updated_at=NOW() WHERE id=?', [$name, $email, $user['id']]);
        Audit::log('update_profile', 'portal_user', (string) $user['id'], $before, ['name' => $name, 'email' => $email]);
        return $this->ok('/profile', 'Profile updated.');
    }

    /** The interface is English only; kept so old forms get an answer instead of a 404. */
    public function language(): RedirectResponse
    {
        return $this->ok('/profile', 'The interface is available in English only.');
    }

    public function password(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        if (!password_verify($current, (string) $user['password_hash'])) {
            return $this->fail('Current password is incorrect.');
        }
        if ($new !== (string) $request->input('new_password_confirm', '')) {
            return $this->fail('New password confirmation does not match.');
        }
        if (strlen($new) < 10) {
            return $this->fail('New password must be at least 10 characters.');
        }
        if (password_verify($new, (string) $user['password_hash'])) {
            return $this->fail('Choose a password different from your current password.');
        }
        if (Db::exec('UPDATE portal_users SET password_hash=?, session_version=session_version+1, updated_at=NOW() WHERE id=? AND session_version=?', [password_hash($new, PASSWORD_DEFAULT), $user['id'], $user['session_version']]) !== 1) {
            return $this->fail('Credentials changed; sign in again.');
        }
        Audit::log('change_password', 'portal_user', (string) $user['id']);
        return $this->ok('/profile', 'Password changed.');
    }

    public function twoFactorSetup(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        if (!empty($user['two_factor_enabled'])) {
            return redirect('/profile');
        }
        $secret = (string) $request->session()->get('profile_2fa_secret', '');
        if ($secret === '') {
            $secret = (string) ($user['two_factor_secret'] ?? '');
            if ($secret === '') {
                $secret = Security::twoFactorSecret();
                $request->session()->put('profile_2fa_secret', $secret);
            }
        }
        return Inertia::render('profile/TwoFactorSetup', [
            'secret' => $secret,
            'uri' => Security::twoFactorUri((string) App::env('APP_NAME', 'FreeRADIUS Web'), (string) $user['username'], $secret),
        ]);
    }

    public function twoFactorStart(Request $request): RedirectResponse
    {
        if (!empty(Auth::user()['two_factor_enabled'])) {
            return $this->fail('Two-factor authentication is already enabled.');
        }
        $request->session()->put('profile_2fa_secret', Security::twoFactorSecret());
        return redirect('/profile/2fa/setup');
    }

    public function twoFactorEnable(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (!empty($user['two_factor_enabled'])) {
            return $this->fail('Two-factor authentication is already enabled.');
        }
        $secret = (string) $request->session()->get('profile_2fa_secret', '') ?: (string) ($user['two_factor_secret'] ?? '');
        if ($secret === '') {
            return $this->fail('Two-factor setup expired. Start setup again.', '/profile');
        }
        if (!Auth::consumeTotp($user, $secret, (string) $request->input('code', ''))) {
            return $this->fail('Authenticator code is invalid.');
        }
        if (Db::exec('UPDATE portal_users SET two_factor_secret=?, two_factor_enabled=1, session_version=session_version+1, updated_at=NOW() WHERE id=? AND session_version=?', [$secret, $user['id'], $user['session_version']]) !== 1) {
            return $this->fail('Credentials changed; sign in again.');
        }
        $this->keepSession($request);
        Audit::log('enable_2fa', 'portal_user', (string) $user['id']);
        return $this->ok('/profile', 'Two-factor authentication enabled.');
    }

    public function twoFactorDisable(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (empty($user['two_factor_enabled']) && empty($user['two_factor_secret'])) {
            return $this->fail('Two-factor authentication is not enabled.');
        }
        if (Db::exec('UPDATE portal_users SET two_factor_secret=NULL, two_factor_enabled=0, session_version=session_version+1, mfa_last_counter=NULL, updated_at=NOW() WHERE id=? AND session_version=?', [$user['id'], $user['session_version']]) !== 1) {
            return $this->fail('Credentials changed; sign in again.');
        }
        $this->keepSession($request);
        Audit::log('disable_2fa', 'portal_user', (string) $user['id']);
        return $this->ok('/profile', 'Two-factor authentication disabled.');
    }

    /**
     * A 2FA change bumps session_version (signing out other sessions); this session follows it,
     * as the legacy portal's Auth::user() refresh did.
     */
    private function keepSession(Request $request): void
    {
        $request->session()->forget('profile_2fa_secret');
        $request->session()->put('session_version', (int) Db::value('SELECT session_version FROM portal_users WHERE id=?', [Auth::id()]));
        Auth::refresh();
    }
}
