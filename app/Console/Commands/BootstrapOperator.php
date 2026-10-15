<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Db;
use App\Support\Security;
use RuntimeException;
use Throwable;

/** Fresh-install bootstrap only. Credentials arrive over stdin, never argv or a default demo secret. */
final class BootstrapOperator extends FrwebCommand
{
    protected $signature = 'frweb:bootstrap-operator {--create-first-superadmin : Read JSON {username,name,email,password} from stdin}';
    protected $description = 'Create the first superadmin on an empty install';

    public function handle(): int
    {
        try {
            if (!$this->option('create-first-superadmin')) {
                throw new RuntimeException('Use --create-first-superadmin with JSON {username,name,email,password} on stdin.');
            }
            $input = json_decode((string) stream_get_contents(STDIN, 4096), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($input) || strlen((string) ($input['password'] ?? '')) < 16 || !preg_match('/^[A-Za-z0-9_.-]{3,80}$/D', (string) ($input['username'] ?? '')) || !filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Valid identity/email and a password of at least 16 characters are required.');
            }
            $lock = 'frweb:bootstrap:' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 30);
            if ((int) Db::value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
                throw new RuntimeException('Bootstrap already running.');
            }
            try {
                if (Db::value('SELECT COUNT(*) FROM portal_users')) {
                    throw new RuntimeException('Operator records already exist; use normal administration.');
                }
                Db::exec('INSERT INTO portal_users (username,name,email,password_hash,role,enabled,two_factor_secret,created_at,updated_at) VALUES (?,?,?,?,"superadmin",1,?,NOW(),NOW())',
                    [$input['username'], $input['name'] ?? $input['username'], $input['email'], password_hash($input['password'], PASSWORD_DEFAULT), Security::twoFactorSecret()]);
                $this->line('First operator created; authenticator enrollment is required after password login.');
            } finally {
                Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Bootstrap failed', $e, $e instanceof RuntimeException);
        }
    }
}
