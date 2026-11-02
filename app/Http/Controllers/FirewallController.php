<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Auth;
use App\Support\Firewall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/** Settings → Firewall: the management networks, the rules, turning it on and off, Apply and its confirmation. */
final class FirewallController extends Controller
{
    private const PAGE = '/settings?tab=firewall';

    /** @return array<string, mixed> props for the Firewall tab (SettingsController::settings) */
    public static function props(): array
    {
        $ip = self::clientIp();
        Firewall::ensureTemplate($ip);
        $config = Firewall::config();
        $active = Firewall::active();
        $status = Firewall::status();
        $routers = Firewall::routers();
        $host = Firewall::host();
        $draft = Firewall::draft();
        $draft['config']['enabled'] = true;
        try {
            $preview = $config['management_networks'] === [] ? '# List at least one management network.' : Firewall::render($draft, $host['ssh_ports']);
        } catch (RuntimeException $e) {
            $preview = '# ' . $e->getMessage();
        }
        $warnings = [];
        if (!Firewall::inside($ip, $config['management_networks']) && !in_array($ip, ['127.0.0.1', '::1'], true)) {
            $warnings[] = 'Your address ' . $ip . ' is not in the management networks. Add it before you turn the firewall on.';
        }
        if ($host['ufw_active']) {
            $warnings[] = 'ufw is also active on this server, and its rules apply as well as these. Once this firewall works, you can turn ufw off with sudo ufw disable.';
        }
        if ($routers['networks'] === []) {
            $warnings[] = 'No router has an IP address yet, so the "routers" rule lets nobody in. Add your routers under NAS first.';
        }
        if ($routers['names'] !== []) {
            $warnings[] = 'These routers use a name instead of an address, so the "routers" rule cannot include them: ' . implode(', ', array_slice($routers['names'], 0, 10)) . '. Add a rule with their addresses.';
        }

        return [
            'config' => $config,
            'rules' => Firewall::rules(),
            'routers' => $routers['networks'],
            'active' => $active === null ? null : ['by' => (string) ($active['by'] ?? ''), 'at' => (string) ($active['at'] ?? '')],
            'status' => $status,
            'on' => !empty($active['config']['enabled']) && !in_array($status['state'] ?? '', ['none', 'disabled'], true),
            'pending' => Firewall::pending(),
            'busy' => Firewall::busy(),
            'preview' => $preview,
            'warnings' => $warnings,
            'clientIp' => $ip,
            'username' => (string) (Auth::user()['username'] ?? ''),
            'ready' => Firewall::ready(),
            'sshPorts' => $host['ssh_ports'],
        ];
    }

    public function status(): JsonResponse
    {
        return response()->json(Firewall::status() + ['busy' => Firewall::busy()]);
    }

    public function management(Request $request): RedirectResponse
    {
        $lines = preg_split('/\R/', (string) $request->input('networks', '')) ?: [];

        return $this->attempt(function () use ($lines): string {
            $before = Firewall::config()['management_networks'];
            $after = Firewall::saveManagement($lines, self::clientIp());
            Audit::log('firewall.management', 'firewall', null, $before, $after);

            return 'Management networks saved.';
        });
    }

    public function addRule(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): string {
            $id = Firewall::addRule($this->formInput($request), self::user(), self::clientIp());
            Audit::log('firewall.rule_added', 'firewall_rule', (string) $id, null, $request->only(['action', 'protocol', 'source', 'ports', 'comment', 'minutes']));

            return 'Rule added.';
        });
    }

    public function updateRule(Request $request, int $id): RedirectResponse
    {
        return $this->attempt(function () use ($request, $id): string {
            Firewall::updateRule($id, $this->formInput($request), self::clientIp());
            Audit::log('firewall.rule_updated', 'firewall_rule', (string) $id, null, $request->only(['action', 'protocol', 'source', 'ports', 'comment']));

            return 'Rule saved.';
        });
    }

    public function toggleRule(int $id): RedirectResponse
    {
        return $this->attempt(function () use ($id): string {
            Firewall::toggleRule($id);
            Audit::log('firewall.rule_toggled', 'firewall_rule', (string) $id);

            return 'Rule changed.';
        });
    }

    public function moveRule(Request $request, int $id): RedirectResponse
    {
        return $this->attempt(function () use ($request, $id): string {
            Firewall::moveRule($id, (int) $request->input('by', 1));

            return 'Rule moved.';
        });
    }

    public function deleteRule(int $id): RedirectResponse
    {
        return $this->attempt(function () use ($id): string {
            Firewall::deleteRule($id);
            Audit::log('firewall.rule_deleted', 'firewall_rule', (string) $id);

            return 'Rule deleted.';
        });
    }

    public function template(): RedirectResponse
    {
        return $this->attempt(function (): string {
            Firewall::restoreTemplate(self::user());
            Audit::log('firewall.template_restored', 'firewall');

            return 'The template\'s rules are back.';
        });
    }

    public function enable(): RedirectResponse
    {
        return $this->attempt(function (): string {
            $id = Firewall::enable(self::user(), self::clientIp());
            Audit::log('firewall.enabled', 'firewall', $id);

            return 'Turning the firewall on…';
        });
    }

    public function disable(): RedirectResponse
    {
        return $this->attempt(function (): string {
            $id = Firewall::disable(self::user());
            Audit::log('firewall.disabled', 'firewall', $id);

            return 'Turning the firewall off…';
        });
    }

    public function apply(): RedirectResponse
    {
        return $this->attempt(function (): string {
            $id = Firewall::apply(self::user(), self::clientIp());
            Audit::log('firewall.applied', 'firewall', $id);

            return 'Applying the rules…';
        });
    }

    /** The page reached the server with the new rules in force. */
    public function confirm(Request $request): RedirectResponse
    {
        if (!Firewall::confirm((string) $request->input('id', ''), self::user())) {
            return $this->fail('Nothing is waiting for a confirmation; the rules may already have been put back.', self::PAGE);
        }

        return redirect(self::PAGE);
    }

    /** The visitor's address after trusted proxies, as the firewall sees it. */
    private static function clientIp(): string
    {
        return (string) request()->ip();
    }

    private static function user(): string
    {
        return (string) (Auth::user()['username'] ?? '');
    }

    /** @param callable(): string $change */
    private function attempt(callable $change): RedirectResponse
    {
        try {
            return $this->ok(self::PAGE, $change());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->fail($e->getMessage(), self::PAGE);
        }
    }
}
