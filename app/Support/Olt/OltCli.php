<?php

declare(strict_types=1);

namespace App\Support\Olt;

use RuntimeException;

/**
 * The OLT's command line, for reading only. It sends nothing but "display" commands and the few lines needed to reach
 * them, so nothing that goes through this class can change the OLT. Changes go through OltManager.
 */
final class OltCli
{
    /**
     * enable and scroll only affect this login: scroll stops the output pausing, and nothing is saved on the OLT.
     * config and interface gpon only move to where some display commands live.
     */
    private const ALLOWED = ['enable', 'scroll', 'config', 'quit'];
    private const ALLOWED_PATTERNS = ['/^display [a-z0-9][a-z0-9 \/-]*$/D', '/^interface gpon \d{1,2}\/\d{1,2}$/D'];

    private OltTelnet $telnet;

    public function __construct(string $host, int $port, string $user, string $password, float $budget = 240.0)
    {
        $this->telnet = new OltTelnet($host, $port, $user, $password, $budget);
    }

    /** Logs in, reads the MAC table and logs out. */
    public function macTable(): string
    {
        return $this->read(['display mac-address all'])[0];
    }

    /**
     * Logs in, runs these read commands in order and logs out.
     *
     * @param  list<string>  $commands
     * @return list<string> what the OLT printed for each
     */
    public function read(array $commands): array
    {
        $this->telnet->open();
        try {
            $this->send('scroll');
            return array_map($this->send(...), $commands);
        } finally {
            $this->telnet->close();
        }
    }

    /** Only proves that this user can log in. */
    public function check(): void
    {
        $this->telnet->open();
        $this->telnet->close();
    }

    private function send(string $command): string
    {
        if (!in_array($command, self::ALLOWED, true) && !array_filter(self::ALLOWED_PATTERNS, static fn (string $p) => preg_match($p, $command) === 1)) {
            throw new RuntimeException('This command is not allowed on the OLT.');
        }
        return $this->telnet->command($command);
    }
}
