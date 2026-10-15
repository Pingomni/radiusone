<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\Db;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams every match of a paged query as CSV, read from one consistent snapshot. */
final class Csv
{
    /**
     * @param callable(int): array{rows: array, pages: int} $queryPage
     * @param callable(array): array $cells
     */
    public static function download(string $filename, array $headers, callable $queryPage, callable $cells): StreamedResponse
    {
        return response()->streamDownload(static function () use ($headers, $queryPage, $cells): void {
            $out = fopen('php://output', 'wb');
            $pdo = Db::pdo();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            try {
                fputcsv($out, $headers, escape: '\\');
                $page = 1;
                do {
                    $result = $queryPage($page);
                    foreach ($result['rows'] as $row) {
                        // A leading = + @ - would run as a formula in spreadsheets.
                        fputcsv($out, array_map(static fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'" . $v : $v, $cells($row)), escape: '\\');
                    }
                } while (++$page <= $result['pages']);
                $pdo->commit();
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                fclose($out);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
