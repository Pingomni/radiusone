<?php

namespace App\Http\Middleware;

use App\Support\Auth;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Props every page receives. Flash messages use the legacy shape {type, message}
     * with type one of success | danger | warning | info.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = Auth::user();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                    'name' => (string) ($user['name'] ?? ''),
                    'email' => (string) ($user['email'] ?? ''),
                    'role' => (string) $user['role'],
                    'two_factor_enabled' => (bool) $user['two_factor_enabled'],
                ] : null,
                'permissions' => $user ? Auth::permissions() : [],
            ],
            'flash' => fn () => $request->session()->get('flash'),
            'logo' => fn () => Settings::logo(),
            'clock' => fn () => ['time' => date('H:i'), 'timezone' => date_default_timezone_get()],
        ];
    }
}
