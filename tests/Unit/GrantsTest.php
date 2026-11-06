<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Production runs the portal as radiusone_portal, which may use only the tables ops/install/radiusone-grants.sql
 * grants it; the test suite runs with a full-rights account and would not notice a table left out (2.9.0 shipped
 * contracts and the pay page that way, and the portal failed on rdb). So every table a migration creates must be granted.
 */
final class GrantsTest extends TestCase
{
    public function test_every_table_a_migration_creates_is_granted_to_the_portal(): void
    {
        $root = dirname(__DIR__, 2);
        $tables = [];
        foreach (glob($root . '/database/sql/*.sql') ?: [] as $file) {
            preg_match_all('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', (string) file_get_contents($file), $m);
            $tables = array_merge($tables, $m[1]);
        }
        preg_match_all('/ON `radius`\.`(\w+)` TO `radiusone_portal`/', (string) file_get_contents($root . '/ops/install/radiusone-grants.sql'), $m);
        $this->assertNotEmpty($tables);
        $this->assertSame([], array_values(array_diff(array_unique($tables), $m[1])), 'Grant these tables to radiusone_portal in ops/install/radiusone-grants.sql.');
    }
}
