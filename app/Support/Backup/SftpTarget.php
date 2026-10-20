<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\BoundedProcess;
use RuntimeException;

/**
 * Any SSH server, through the system sftp client. The app generates an ed25519 key pair (the operator
 * adds the public key to the server's authorized_keys) and pins the server's host key when the
 * destination is saved: ssh never prompts and never trusts a changed host.
 */
final class SftpTarget implements BackupTarget
{
    public function __construct(private readonly array $c) {}

    public static function validate(array $c): void
    {
        if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $c['host'] ?? '') || (int) ($c['port'] ?? 0) < 1 || (int) $c['port'] > 65535
            || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $c['user'] ?? '') || !preg_match('#^[A-Za-z0-9._/~-]{1,255}$#D', $c['path'] ?? '')) {
            throw new \InvalidArgumentException('Enter the SFTP host, port, user name and folder (letters, digits, . _ - / ~).');
        }
    }

    /** @return array{private_key: string, public_key: string} */
    public static function generateKey(): array
    {
        $dir = self::tempDir();
        try {
            $result = BoundedProcess::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'freeradius-web-backup', '-f', $dir . '/key'], '', 10);
            if ($result['exit'] !== 0) {
                throw new RuntimeException('Could not generate an SSH key.');
            }
            return ['private_key' => (string) file_get_contents($dir . '/key'), 'public_key' => trim((string) file_get_contents($dir . '/key.pub'))];
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * The server's current host keys and their fingerprint, recorded once and then required on every connection.
     *
     * @return array{known_hosts: string, fingerprint: string}
     */
    public static function scanHostKey(string $host, int $port): array
    {
        $scan = BoundedProcess::run(['ssh-keyscan', '-T', '8', '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $host], '', 15);
        $lines = trim($scan['output']);
        if ($lines === '') {
            throw new RuntimeException('Could not reach ' . $host . ':' . $port . ' to read its host key.');
        }
        $dir = self::tempDir();
        try {
            file_put_contents($dir . '/known_hosts', $lines . "\n");
            $fingerprints = BoundedProcess::run(['ssh-keygen', '-l', '-f', $dir . '/known_hosts'], '', 10);
            $first = strtok(trim($fingerprints['output']), "\n");
            return ['known_hosts' => $lines, 'fingerprint' => $first !== false ? implode(' ', array_slice(explode(' ', $first), 1)) : 'unknown'];
        } finally {
            self::removeDir($dir);
        }
    }

    public function put(string $file, string $name): void
    {
        $this->batch('-mkdir ' . $this->dir() . "\nput " . $file . ' ' . $this->dir() . '/' . self::name($name) . "\n", 900);
    }

    public function list(): array
    {
        $output = $this->batch('ls -1 ' . $this->dir() . "\n", 60);
        $files = [];
        foreach (explode("\n", $output) as $line) {
            $name = basename(trim($line));
            if (str_starts_with($name, 'frweb-')) {
                $files[$name] = $name;
            }
        }
        return $files;
    }

    public function delete(string $id): void
    {
        $this->batch('rm ' . $this->dir() . '/' . self::name($id) . "\n", 60);
    }

    private function dir(): string
    {
        return rtrim($this->c['path'], '/') ?: '.';
    }

    private static function name(string $name): string
    {
        if (!preg_match('/^frweb-[A-Za-z0-9._-]+$/D', $name)) {
            throw new RuntimeException('Unexpected backup file name.');
        }
        return $name;
    }

    private function batch(string $commands, int $seconds): string
    {
        $dir = self::tempDir();
        try {
            $old = umask(0077);
            file_put_contents($dir . '/key', rtrim($this->c['private_key']) . "\n");
            file_put_contents($dir . '/known_hosts', $this->c['known_hosts'] . "\n");
            umask($old);
            $result = BoundedProcess::run(['sftp', '-b', '-', '-F', '/dev/null', '-P', (string) (int) $this->c['port'], '-i', $dir . '/key',
                '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' . $dir . '/known_hosts',
                '-o', 'ConnectTimeout=15', $this->c['user'] . '@' . $this->c['host']], $commands, $seconds, 1048576);
            if ($result['exit'] !== 0) {
                $error = trim($result['error']);
                throw new RuntimeException('SFTP failed: ' . (str_contains($error, 'Host key verification failed') ? 'the server host key changed; re-check the server and save the destination again.'
                    : (str_contains($error, 'Permission denied') ? 'the server refused the key; add the public key to ~/.ssh/authorized_keys.' : mb_substr($error ?: 'exit ' . $result['exit'], 0, 200))));
            }
            return $result['output'];
        } finally {
            self::removeDir($dir);
        }
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/frweb-sftp-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Could not create a private temporary folder.');
        }
        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
