<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\App;
use App\Support\Auth;
use App\Support\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Sign-in, two-factor, re-authentication and logout. */
final class AuthController extends Controller
{
    public function loginPage(): Response|RedirectResponse
    {
        if (Auth::user()) {
            return redirect('/');
        }
        return Inertia::render('auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (Auth::attempt((string) $request->input('username', ''), (string) $request->input('password', ''))) {
            if (Auth::twoFactorPending()) {
                return redirect('/two-factor');
            }
            if (Auth::twoFactorSetupPending()) {
                return redirect('/two-factor/setup');
            }
            return redirect('/');
        }
        return redirect('/login')->with('flash', ['type' => 'danger', 'message' => 'Invalid credentials or login is temporarily rate-limited.']);
    }

    public function twoFactorPage(): Response|RedirectResponse
    {
        if (!Auth::twoFactorPending()) {
            return redirect('/login');
        }
        return Inertia::render('auth/TwoFactor', ['setup' => null]);
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        if (Auth::completeTwoFactor((string) $request->input('code', ''))) {
            return redirect('/');
        }
        return redirect('/two-factor')->with('flash', ['type' => 'danger', 'message' => 'Invalid authentication code.']);
    }

    public function twoFactorSetupPage(): Response|RedirectResponse
    {
        $pending = Auth::pendingTwoFactorSetupUser();
        if (!$pending) {
            return redirect('/login');
        }
        $secret = (string) $pending['two_factor_secret'];
        return Inertia::render('auth/TwoFactor', ['setup' => [
            'secret' => $secret,
            'uri' => Security::twoFactorUri((string) App::env('APP_NAME', 'FreeRADIUS Web'), (string) $pending['username'], $secret),
        ]]);
    }

    public function twoFactorSetup(Request $request): RedirectResponse
    {
        if (Auth::completeTwoFactorSetup((string) $request->input('code', ''))) {
            return redirect('/');
        }
        return redirect('/two-factor/setup')->with('flash', ['type' => 'danger', 'message' => 'Invalid authentication code.']);
    }

    public function logout(): HttpResponse
    {
        Auth::logout();
        return Inertia::location('/login');
    }

    public function reauthPage(): Response
    {
        return Inertia::render('auth/Reauth', [
            'returnPath' => Auth::reauthReturnPath(),
            'needsCode' => !empty(Auth::user()['two_factor_enabled']),
        ]);
    }

    public function reauth(Request $request): RedirectResponse
    {
        $returnPath = Auth::reauthReturnPath();
        if (Auth::reauthenticate((string) $request->input('password', ''), (string) $request->input('code', ''))) {
            $request->session()->forget('reauth_return');
            return $this->ok($returnPath, 'Identity confirmed. Review the form, re-enter credentials or files as needed, then submit again. Nothing was submitted automatically.');
        }
        $hasMfa = !empty(Auth::user()['two_factor_enabled']);
        $errors = ['password' => 'Confirmation failed. Check your current password' . ($hasMfa ? ' and authentication code' : '') . ', or wait if confirmation is temporarily locked.'];
        if ($hasMfa) {
            $errors['code'] = 'Enter a fresh authentication code along with your current password.';
        }
        throw ValidationException::withMessages($errors)->redirectTo('/reauth');
    }
}
