<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Operators;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use PDOException;

/** Portal users (operators). The list itself is the Users tab of Settings. */
final class UserController extends Controller
{
    public function index(): RedirectResponse
    {
        Auth::requireCan('portal_users.manage');
        return redirect('/settings?tab=users');
    }

    public function form(?string $id = null): Response
    {
        Auth::requireCan('portal_users.manage');
        $row = $id ? Db::row('SELECT * FROM portal_users WHERE id = ?', [$id]) : null;
        abort_if($id && !$row, 404, 'Operator not found.');

        $self = $row && (int) $row['id'] === Auth::id();
        $roles = Operators::ROLES;
        // Superadmin is offered only to superadmins (or kept when the edited operator already has it).
        if ((Auth::user()['role'] ?? '') !== 'superadmin' && ($row['role'] ?? '') !== 'superadmin') {
            unset($roles['superadmin']);
        }
        return Inertia::render('settings/UserForm', [
            'user' => $row ? [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'email' => (string) $row['email'],
                'username' => (string) $row['username'],
                'role' => (string) $row['role'],
                'enabled' => (bool) $row['enabled'],
                'lastLogin' => (string) (($row['last_login_at'] ?? '') ?: '-'),
                'twoFactor' => Operators::twoFactorLabel($row),
                'twoFactorTag' => Operators::twoFactorTag($row),
            ] : null,
            'self' => $self,
            'roles' => $roles,
            'roleDescriptions' => Operators::roleDescriptions(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        Auth::requireCan('portal_users.manage');
        $input = $request->only(['name', 'email', 'username', 'role', 'password']);
        $input = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $input + ['name' => '', 'email' => '', 'username' => '', 'role' => '', 'password' => '']);
        $id = (int) $request->input('id', 0) ?: null;
        $enabled = $request->boolean('enabled') ? 1 : 0;
        $actor = Auth::user();
        $returnTo = $id ? '/settings/users/' . $id . '/edit' : '/settings/users/create';

        if ($id && (int) $actor['id'] === $id && $input['role'] !== $actor['role']) {
            return $this->fail('You cannot change your own role.', $returnTo);
        }
        if (($actor['role'] ?? '') !== 'superadmin' && $input['role'] === 'superadmin') {
            return $this->fail('Only superadmins can create or edit superadmins.', $returnTo);
        }
        if (!isset(Operators::ROLES[$input['role']])) {
            return $this->failKeepingInput(new InvalidArgumentException('Choose a role.'), $returnTo, ['role' => '/role/']);
        }
        if (trim($input['username']) === '' || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->failKeepingInput(new InvalidArgumentException('Enter a username and a valid email address.'), $returnTo, ['email' => '/email/']);
        }
        if (!$id && strlen($input['password']) < 10) {
            return $this->failKeepingInput(new InvalidArgumentException('New operators need a password of at least 10 characters.'), $returnTo, ['password' => '/password/']);
        }

        $twoFactorSetupKey = '';
        if ($id) {
            $before = Db::row('SELECT * FROM portal_users WHERE id=?', [$id]);
            abort_if(!$before, 404, 'Operator not found.');
            $params = [$input['name'], $input['email'], $input['username'], $input['role'], $enabled];
            $sql = 'UPDATE portal_users SET name=?, email=?, username=?, role=?, enabled=?, session_version=session_version+1, updated_at=NOW()';
            if ($input['password'] !== '') {
                $sql .= ', password_hash=?';
                $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
            }
            $twoFactorAction = (string) $request->input('two_factor_action', 'keep');
            if ($twoFactorAction === 'disable' && in_array($input['role'], Operators::PRIVILEGED, true)) {
                return $this->fail('Privileged roles require two-factor authentication.', $returnTo);
            }
            if ($twoFactorAction === 'disable') {
                $sql .= ', two_factor_secret=NULL, two_factor_enabled=0, mfa_last_counter=NULL';
            } elseif ($twoFactorAction === 'generate') {
                $twoFactorSetupKey = Security::twoFactorSecret();
                $sql .= ', two_factor_secret=?, two_factor_enabled=0, mfa_last_counter=NULL';
                $params[] = $twoFactorSetupKey;
            } elseif ($twoFactorAction !== 'keep') {
                return $this->fail('Choose a valid 2FA action.', $returnTo);
            }
            $sql .= ' WHERE id=? AND session_version=?';
            $params[] = $id;
            $params[] = $before['session_version'];
            try {
                $changed = Db::exec($sql, $params);
            } catch (PDOException $e) {
                return $this->failKeepingInput($e, $returnTo, ['username' => '/./']);
            }
            if ($changed !== 1) {
                return $this->fail('Operator changed; reload before saving.', $returnTo);
            }
            Audit::log('update', 'portal_user', (string) $id, $before, array_diff_key($input, ['password' => true]) + ['enabled' => $enabled, 'password_changed' => $input['password'] !== '', 'two_factor_changed' => $twoFactorAction !== 'keep']);
        } else {
            try {
                Db::exec('INSERT INTO portal_users (name,email,username,password_hash,role,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())', [
                    $input['name'], $input['email'], $input['username'], password_hash($input['password'], PASSWORD_DEFAULT), $input['role'], $enabled,
                ]);
            } catch (PDOException $e) {
                return $this->failKeepingInput($e, $returnTo, ['username' => '/./']);
            }
            Audit::log('create', 'portal_user', (string) Db::pdo()->lastInsertId(), null, array_diff_key($input, ['password' => true]) + ['enabled' => $enabled]);
        }

        $message = 'User saved.';
        if ($twoFactorSetupKey !== '') {
            $message .= ' 2FA setup will be required for ' . $input['username'] . ' on next login.';
        }
        return $this->ok('/settings?tab=users', $message);
    }

    public function delete(string $id): RedirectResponse
    {
        Auth::requireCan('danger.delete');
        if (Auth::id() === (int) $id) {
            return $this->fail('You cannot delete your own user.');
        }
        $before = Db::row('SELECT * FROM portal_users WHERE id=?', [$id]);
        abort_if(!$before, 404, 'Operator not found.');
        Db::exec('DELETE FROM portal_users WHERE id=?', [$id]);
        Audit::log('delete', 'portal_user', $id, $before);
        return $this->ok('/settings?tab=users', 'User deleted.');
    }
}
