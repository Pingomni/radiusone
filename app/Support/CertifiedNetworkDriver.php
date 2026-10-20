<?php

declare(strict_types=1);

namespace App\Support;

/** Release/topology-specific driver boundary. A missing certified driver is an explicit rollout gate. */
final class CertifiedNetworkDriver implements NetworkDriver
{
    public function observe(array $profile, array $session, array $policy, string $action): array
    {
        return $this->call('observe', $profile, $session, $policy, $action);
    }
    public function apply(array $profile, array $session, array $policy, string $action): array
    {
        return $this->call('apply', $profile, $session, $policy, $action);
    }
    private function call(string $verb, array $profile, array $session, array $policy, string $action): array
    {
        $adapter = AccessPolicy::adapter($profile['adapter']);
        $path = (string) App::env('NETWORK_DRIVER_' . strtoupper($adapter->key()), '');
        // Trusted executable, never a shell command or subscriber-controlled path.
        if ($path === '' || !str_starts_with($path, '/') || is_link($path) || !is_executable($path) || fileowner($path) !== 0 || (fileperms($path) & 0022)) {
            throw new \RuntimeException('A root-owned certified network driver is required for this adapter.');
        }
        for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
            if (is_link($parent) || fileowner($parent) !== 0 || (fileperms($parent) & 0022)) {
                throw new \RuntimeException('Network driver directory must be root-controlled.');
            }
        }
        $payload = ['version' => 1, 'verb' => $verb, 'nas_id' => (int) $profile['nas_id'], 'profile_id' => (int) $profile['id'], 'profile_revision' => (int) $profile['revision'],
            'software_version' => $profile['software_version'], 'session' => $session, 'policy' => $policy, 'operation' => $adapter->operation($action, $policy)];
        $result = BoundedProcess::run([$path], json_encode($payload, JSON_THROW_ON_ERROR), 10, 16384);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Network driver failed.');
        }
        $reply = json_decode($result['output'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($reply)) {
            throw new \RuntimeException('Invalid network driver result.');
        }
        return $reply;
    }
}
