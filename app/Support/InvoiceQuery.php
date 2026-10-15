<?php
declare(strict_types=1);
namespace App\Support;

final class InvoiceQuery
{
    public static function conditions(array $filters): array
    {
        $conditions = [];
        $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['draft', 'open', 'partial', 'paid', 'void', 'overdue'], true)) {
            $conditions[] = 'i.status = ?';
            $params[] = $status;
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $conditions[] = '(i.invoice_number LIKE ? OR i.username LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
        }
        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    public static function page(array $filters, int $page = 1, int $size = 30): array
    {
        [$where, $params] = self::conditions($filters);
        $from = 'FROM billing_invoices i JOIN customer_profiles c ON c.username=i.username
            LEFT JOIN service_metadata s ON s.group_name=i.service_group';
        $size = max(1, min(200, $size));
        $total = (int) Db::value("SELECT COUNT(*) $from $where", $params);
        $pages = max(1, (int) ceil($total / $size));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $size;
        $rows = Db::select("SELECT i.*, c.first_name, c.last_name, c.company_name, s.name AS service_name
            $from $where ORDER BY i.created_at DESC, i.id DESC LIMIT $size OFFSET $offset", $params);
        return compact('rows', 'total', 'page', 'pages', 'size');
    }
}
