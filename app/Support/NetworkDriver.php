<?php
declare(strict_types=1);
namespace App\Support;
/** Observation must come from the NAS/forwarding plane, never an accounting timestamp or an ACK. */
interface NetworkDriver
{
    public function observe(array $profile,array $session,array $policy,string $action): array;
    public function apply(array $profile,array $session,array $policy,string $action): array;
}
