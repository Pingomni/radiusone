<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class BoundedProcess
{
    public static function run(array $argv, string $input, float $seconds = 8, int $limit = 65536): array
    {
        $process = proc_open($argv, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start process.');
        }
        $deadline = microtime(true) + $seconds;
        $output = '';
        $error = '';
        $exit = -1;
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            $offset = 0;
            while (true) {
                if (is_resource($pipes[0])) {
                    if ($offset < strlen($input)) {
                        $n = fwrite($pipes[0], substr($input, $offset));
                        if ($n === false) {
                            throw new RuntimeException('Process input failed.');
                        }$offset += $n;
                    }
                    if ($offset === strlen($input)) {
                        fclose($pipes[0]);
                    }
                }
                $output .= stream_get_contents($pipes[1]);
                $error .= stream_get_contents($pipes[2]);
                if (strlen($output) + strlen($error) > $limit) {
                    throw new RuntimeException('Process output limit exceeded.');
                }
                $state = proc_get_status($process);
                if (!$state['running']) {
                    $exit = $state['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Operation timed out; verify the same session before retrying.');
                }
                usleep(10000);
            }
            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);
            return ['exit' => $exit, 'output' => $output, 'error' => $error];
        } finally {
            $state = proc_get_status($process);
            if ($state['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }
}
