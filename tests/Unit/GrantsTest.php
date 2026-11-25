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

    /** Delete permanently empties many tables as radiusone_portal; 3.1.0 shipped without DELETE on them and failed. */
    public function test_the_portal_may_delete_from_every_table_delete_permanently_empties(): void
    {
        $root = dirname(__DIR__, 2);
        $code = (string) file_get_contents($root . '/app/Support/RadiusRepository.php');
        $start = strpos($code, 'public static function purgeClient');
        $body = substr($code, (int) $start, (int) strpos($code, 'public static function saveService') - (int) $start);
        preg_match_all("/\\\$delete\\('(\\w+)'|DELETE (?:\\w+ )?FROM (\\w+)/", $body, $m);
        $tables = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));
        // The table lists in loops: foreach ([...] as $table) { $delete($table, ...) }.
        preg_match_all("/foreach \\(\\[([^\\]]+)\\] as \\\$table\\)/", $body, $loops);
        foreach ($loops[1] as $list) {
            preg_match_all("/'(\\w+)'/", $list, $names);
            $tables = array_merge($tables, $names[1]);
        }
        $tables = array_values(array_unique($tables));
        $this->assertContains('customer_profiles', $tables);
        $this->assertContains('network_operations', $tables);

        $grants = (string) file_get_contents($root . '/ops/install/radiusone-grants.sql');
        $missing = array_values(array_filter($tables, static fn (string $t) => !preg_match('/^GRANT [^;]*\bDELETE\b[^;]* ON `radius`\.`' . $t . '` TO `radiusone_portal`/m', $grants)));
        $this->assertSame([], $missing, 'Grant DELETE on these tables to radiusone_portal in ops/install/radiusone-grants.sql.');
    }
}
