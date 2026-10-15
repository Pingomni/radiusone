<?php
declare(strict_types=1);
namespace App\Support;

final class ClientQuery
{
    /** @var array<string, list<string>> */
    private static array $usernames = [];

    /**
     * The client's current username plus every previous one (renames), resolved once per request,
     * so accounting queries can use a plain indexed "username IN (...)".
     * @return list<string>
     */
    public static function usernames(string $username): array
    {
        return self::$usernames[$username] ??= array_values(array_unique(array_merge([$username], array_column(Db::select(
            'SELECT h.username FROM customer_identity_history h JOIN customer_profiles c ON c.id=h.customer_id WHERE c.username=?', [$username]
        ), 'username'))));
    }

    /** "<column> IN (?, ?, ...)" for usernames(). */
    public static function inUsernames(string $column, array $usernames): string
    {
        return $column . ' IN (' . implode(',', array_fill(0, max(1, count($usernames)), '?')) . ')';
    }

    public static function identity(string $alias): string
    {
        return "($alias.username=c.username OR $alias.username IN (SELECT h.username FROM customer_identity_history h WHERE h.customer_id=c.id))";
    }

    public static function page(array $filters, int $page = 1, int $size = 30): array
    {
        [$where, $params] = self::conditions($filters);
        $size = max(1, min(200, $size));
        $total = (int) Db::value("SELECT COUNT(*) FROM customer_profiles c WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $size));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $size;
        $rows = Db::select("SELECT c.*, s.name AS service_name
            FROM customer_profiles c LEFT JOIN service_metadata s ON s.group_name=c.service_group
            WHERE $where ORDER BY c.updated_at DESC, c.id DESC LIMIT $size OFFSET $offset", $params);
        self::accountingForPage($rows);
        return compact('rows', 'total', 'page', 'pages', 'size');
    }

    private static function accountingForPage(array &$rows): void
    {
        if (!$rows) { return; }
        $positions=[];
        foreach($rows as $i=>&$row){
            $positions[$row['id']]=$i;
            $row += ['online_ip'=>null,'online_nas'=>null,'online_count'=>0,'stale_count'=>0,'down'=>0,'up'=>0];
        }
        unset($row);
        $ids=array_keys($positions);$in=implode(',',array_fill(0,count($ids),'?'));
        // Let SQL resolve identity equality/collation; a PHP string-key map would
        // miss accounting usernames with case variants accepted by RADIUS SQL.
        $owners="(SELECT id AS customer_id,username FROM customer_profiles WHERE id IN ($in)
            UNION SELECT customer_id,username FROM customer_identity_history WHERE customer_id IN ($in)) owners";
        $params=array_merge($ids,$ids);
        foreach(Db::select("SELECT owners.customer_id,SUM(r.acctoutputoctets) AS down,SUM(r.acctinputoctets) AS up FROM $owners JOIN radacct r ON r.username=owners.username GROUP BY owners.customer_id",$params) as $total){
            $i=$positions[$total['customer_id']];$rows[$i]['down']=(int)$total['down'];$rows[$i]['up']=(int)$total['up'];
        }
        $fresh=self::fresh('r');$latest=[];
        foreach(Db::select("SELECT owners.customer_id,SUM(COALESCE(($fresh),0)) AS fresh_count,COUNT(*)-SUM(COALESCE(($fresh),0)) AS stale_count,MAX(IF($fresh,radacctid,NULL)) AS latest FROM $owners JOIN radacct r ON r.username=owners.username WHERE r.acctstoptime IS NULL GROUP BY owners.customer_id",$params) as $open){
            $i=$positions[$open['customer_id']];$rows[$i]['online_count']=(int)$open['fresh_count'];$rows[$i]['stale_count']=(int)$open['stale_count'];
            if($open['latest']!==null){$latest[$open['latest']][]=$i;}
        }
        if($latest){
            $in=implode(',',array_fill(0,count($latest),'?'));
            foreach(Db::select("SELECT radacctid,framedipaddress,nasipaddress FROM radacct WHERE radacctid IN ($in)",array_keys($latest)) as $session){
                foreach($latest[$session['radacctid']] as $i){$rows[$i]['online_ip']=$session['framedipaddress'];$rows[$i]['online_nas']=$session['nasipaddress'];}
            }
        }
    }

    public static function count(array $filters): int
    {
        [$where,$params]=self::conditions($filters);
        return (int)Db::value("SELECT COUNT(*) FROM customer_profiles c WHERE $where",$params);
    }

    private static function existsAccounting(string $predicate): string
    {
        // Materialize the matching customer IDs once. This also avoids per-customer
        // scans when legacy RADIUS and portal username collations differ.
        return "c.id IN (SELECT matched.customer_id FROM (
            SELECT current_customer.id AS customer_id FROM radacct r
            JOIN customer_profiles current_customer ON current_customer.username=r.username WHERE $predicate
            UNION
            SELECT h.customer_id FROM radacct r JOIN customer_identity_history h ON h.username=r.username WHERE $predicate
        ) matched)";
    }

    public static function fresh(string $alias): string
    {
        return Accounting::freshSql($alias);
    }

    public static function conditions(array $filters): array
    {
        $status = (string) ($filters['status'] ?? '');
        $conditions = [$status === 'archived' ? 'c.account_status="archived"' : 'c.account_status<>"archived"'];
        $params = [];
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            // Operators paste whatever they have: a name, phone, static IP, CPE MAC or the IP of a live session.
            $like = '%' . $q . '%';
            $conditions[] = '(c.username LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.cell_phone LIKE ? OR c.framed_ip_address LIKE ? OR c.mac_address LIKE ? OR '
                . self::existsAccounting(self::fresh('r') . ' AND r.framedipaddress LIKE ?') . ')';
            $params = array_fill(0, 10, $like);
        }
        if (!empty($filters['plan'])) { $conditions[] = 'c.service_group=?'; $params[] = $filters['plan']; }
        $fresh = self::fresh('r');
        if (!empty($filters['nas'])) {
            $conditions[] = self::existsAccounting("$fresh AND r.nasipaddress=?");
            $params[] = $filters['nas']; $params[] = $filters['nas'];
        }
        if (in_array($status, ['online', 'offline'], true)) {
            $conditions[] = ($status === 'offline' ? 'NOT ' : '') . self::existsAccounting($fresh);
        // Enabled/disabled/expired match the badge each row shows (accountStatusLabel()).
        } elseif ($status === 'enabled') {
            $conditions[] = 'c.enabled=1 AND c.account_status="active" AND (c.expiry_date IS NULL OR c.expiry_date >= ?)'; $params[] = Entitlement::today();
        }
        elseif ($status === 'disabled') { $conditions[] = '(c.account_status="disabled" OR (c.enabled=0 AND c.account_status NOT IN ("suspended","expired")))'; }
        elseif ($status === 'suspended') { $conditions[] = 'c.account_status="suspended"'; }
        elseif ($status === 'expired') {
            $conditions[] = '(c.account_status="expired" OR (c.enabled=1 AND c.account_status="active" AND c.expiry_date < ?))'; $params[] = Entitlement::today();
        }
        return [implode(' AND ', $conditions), $params];
    }
}
