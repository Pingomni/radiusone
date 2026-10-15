<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class BillingRepository
{
    public static function saveInvoice(array $input, ?int $id = null): int
    {
        SchemaCompatibility::require('billing');
        $pdo=Db::pdo();$owns=!$pdo->inTransaction();
        if($owns){$pdo->beginTransaction();}
        try{
            $saved=self::saveInvoiceLocked($input,$id);
            if($owns){$pdo->commit();SubscriberPolicy::releaseLocks();}
            return $saved;
        }catch(\Throwable $e){if($owns && $pdo->inTransaction()){$pdo->rollBack();SubscriberPolicy::releaseLocks();}throw $e;}
    }

    private static function saveInvoiceLocked(array $input, ?int $id): int
    {
        $username = trim((string) ($input['username'] ?? ''));
        $profile=Db::row('SELECT * FROM customer_profiles WHERE username=? AND account_status<>"archived" FOR UPDATE',[$username]);
        if ($username === '' || !$profile) {
            throw new RuntimeException('Select a valid billing customer.');
        }

        $service = null;
        $serviceGroup = self::blankToNull($input['service_group'] ?? null);
        if ($serviceGroup !== null) {
            $service = Db::row('SELECT group_name, name, price, currency, billing_mode FROM service_metadata WHERE group_name=? AND enabled=1 FOR UPDATE', [$serviceGroup]);
            if (!$service) {
                throw new RuntimeException('Select an enabled service plan.');
            }
        }
        $staticIpPoolKey = self::blankToNull($input['static_ip_pool_key'] ?? null);
        $staticIpCharge = self::staticIpChargeForInvoice($username, $staticIpPoolKey);

        $billingPeriod = self::normalizeBillingPeriod((string) ($input['billing_period'] ?? 'monthly'));
        $billingMonths = self::billingPeriodMonths($billingPeriod);
        $quantity = (float) $billingMonths;
        $unitPrice = $service ? self::decimal($service['price']) : self::decimal($input['unit_price'] ?? 0);
        $discount = self::decimal($input['discount_amount'] ?? 0);
        $tax = self::decimal($input['tax_amount'] ?? 0);
        $serviceSubtotal = round($quantity * $unitPrice, 2);
        $staticIpMonthlyPrice = $staticIpCharge ? self::decimal($staticIpCharge['price']) : 0.0;
        $staticIpTotal = round($staticIpMonthlyPrice * $billingMonths, 2);
        $subtotal = round($serviceSubtotal + $staticIpTotal, 2);
        $total = round($subtotal - $discount + $tax, 2);
        if ($total < 0 || $total > 9999999999.99) { throw new RuntimeException('Invoice total is outside the supported range.'); }
        $currency = strtoupper(trim((string) ($service['currency'] ?? $staticIpCharge['currency'] ?? $input['currency'] ?? 'USD')));
        if(!preg_match('/^[A-Z]{3}$/D',$currency) || (isset($input['currency']) && strtoupper(trim((string)$input['currency']))!==$currency)){throw new RuntimeException('Invoice currency must be a three-letter code matching its service/add-on.');}
        if ($staticIpTotal > 0 && $staticIpCharge && strtoupper((string) $staticIpCharge['currency']) !== $currency) {
            throw new RuntimeException('Static IP pool currency must match the invoice currency.');
        }
        $issueDate = self::dateOrToday($input['issue_date'] ?? null);
        $periodStart = self::dateOrToday($input['period_start'] ?? $issueDate);
        $anchor=isset($input['anchor_day']) ? (int)$input['anchor_day'] : (!Entitlement::isExpired($profile) && !empty($profile['billing_anchor_day']) ? (int)$profile['billing_anchor_day'] : (int)substr($periodStart,8,2));
        $periodEnd = $input['_period_end'] ?? self::periodEnd($periodStart, $billingPeriod, $anchor);
        $dueDate = $periodEnd;
        $description = trim((string) ($input['description'] ?? ''));
        if ($service) {
            $description = ($service['name'] ?: $service['group_name']) . ' service';
        } elseif ($description === '') {
            $description = 'Internet service';
        }
        $status = in_array(($input['status'] ?? 'open'), ['draft', 'open'], true) ? (string) ($input['status'] ?? 'open') : 'open';
        $actor = Auth::user();

        $pdo = Db::pdo();
        if (!$pdo->inTransaction()) { throw new RuntimeException('Invoice save requires a transaction.'); }
        $customer = Db::row('SELECT account_status FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
        if (!$customer || $customer['account_status'] === 'archived') { throw new RuntimeException('Archived customers cannot receive new or edited invoices.'); }
        if($serviceGroup!==null && !Db::row('SELECT group_name FROM service_metadata WHERE group_name=? AND enabled=1 FOR UPDATE',[$serviceGroup])){throw new RuntimeException('Service changed; reload invoice.');}
        if($status!=='draft' && Db::value('SELECT COUNT(*) FROM billing_invoices WHERE username=? AND currency<>? AND status NOT IN ("draft","void")',[$username,$currency])){throw new RuntimeException('Issued customer invoices must use one currency; a currency change requires a reviewed financial migration.');}
        if ($id) {
            $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=? FOR UPDATE', [$id]);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }
            if ($invoice['status'] !== 'draft' || self::invoicePaidAmount($id) > 0 || !empty($invoice['cycle_key'])) {
                throw new RuntimeException('Issued, payment-bearing, generated or void invoices cannot be edited.');
            }
            if ($invoice['username'] !== $username || $invoice['currency'] !== $currency) { throw new RuntimeException('Invoice customer and currency are immutable.'); }
            $amountPaid = self::invoicePaidAmount($id);
            $balance = max(0, round($total - $amountPaid, 2));
            $status = self::statusForBalance($status, $total, $amountPaid, $dueDate);
            Db::exec(
                'UPDATE billing_invoices
                 SET username=?, service_group=?, billing_period=?, period_start=?, period_end=?, issue_date=?, due_date=?,
                     subtotal=?, discount_amount=?, tax_amount=?, total_amount=?, amount_paid=?, balance_due=?,
                     currency=?, status=?, notes=?, updated_at=NOW()
                 WHERE id=?',
                [
                    $username,
                    $serviceGroup,
                    $billingPeriod,
                    $periodStart,
                    $periodEnd,
                    $issueDate,
                    $dueDate,
                    $subtotal,
                    $discount,
                    $tax,
                    $total,
                    $amountPaid,
                    $balance,
                    $currency,
                    $status,
                    trim((string) ($input['notes'] ?? '')),
                    $id,
                ]
            );
            Db::exec('DELETE FROM billing_invoice_items WHERE invoice_id = ?', [$id]);
        } else {
            Db::exec(
                'INSERT INTO billing_invoices
                 (invoice_number, username, service_group, billing_period, period_start, period_end, issue_date, due_date,
                  subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, currency, status,
                  notes, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    'PENDING-' . bin2hex(random_bytes(8)),
                    $username,
                    $serviceGroup,
                    $billingPeriod,
                    $periodStart,
                    $periodEnd,
                    $issueDate,
                    $dueDate,
                    $subtotal,
                    $discount,
                    $tax,
                    $total,
                    $total,
                    $currency,
                    $status,
                    trim((string) ($input['notes'] ?? '')),
                    $actor['id'] ?? null,
                ]
            );
            $id = (int) $pdo->lastInsertId();
            Db::exec('UPDATE billing_invoices SET invoice_number=?, identity_at_issue=? WHERE id=?', [self::invoiceNumber($id), $username, $id]);
        }

        if ($serviceGroup !== null || $serviceSubtotal > 0 || !$staticIpCharge) {
            Db::exec(
                'INSERT INTO billing_invoice_items (invoice_id, description, quantity, unit_price, line_total, service_group, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $id,
                    $description,
                    $quantity,
                    $unitPrice,
                    $serviceSubtotal,
                    $serviceGroup,
                ]
            );
        }
        if ($staticIpTotal > 0 && $staticIpCharge) {
            Db::exec(
                'INSERT INTO billing_invoice_items (invoice_id, description, quantity, unit_price, line_total, service_group, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, NOW())',
                [
                    $id,
                    self::staticIpDescription($staticIpCharge),
                    $billingMonths,
                    $staticIpMonthlyPrice,
                    $staticIpTotal,
                ]
            );
        }
        Db::exec('UPDATE billing_invoices SET cycle_class=?,billing_anchor_day=?,cycle_key=?,replaces_invoice_id=? WHERE id=?',
            [isset($input['_cycle_key']) ? 'generated' : 'manual', $anchor, $input['_cycle_key'] ?? null, $input['_replaces_invoice_id'] ?? null, $id]);
        self::refreshInvoice($id);
        self::refreshCustomerBalance($username);
        return $id;
    }

    public static function createServiceInvoice(string $username, ?string $periodStart = null, bool $skipDuplicate = true): ?int
    {
        SchemaCompatibility::require('billing');
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $client = Db::row('SELECT c.*, s.name AS service_name, s.price, s.currency, s.billing_mode, s.billing_period FROM customer_profiles c JOIN service_metadata s ON s.group_name=c.service_group WHERE c.username=? AND c.account_status<>"archived" AND s.enabled=1 FOR UPDATE', [$username]);
            if (!$client || (float)$client['price'] <= 0) { $pdo->commit();SubscriberPolicy::releaseLocks(); return null; }
            // Prepaid cycles do not drift daily while the preceding generated invoice is unpaid.
            if ($periodStart === null && Db::value('SELECT COUNT(*) FROM billing_invoices WHERE username=? AND (cycle_key IS NOT NULL OR cycle_class="generated") AND status NOT IN ("paid","void","draft")', [$username])) {
                $pdo->commit();SubscriberPolicy::releaseLocks(); return null;
            }
            $start = self::dateOrToday($periodStart ?: self::nextPeriodStart($client));
            $lapsed = $periodStart === null && Entitlement::isExpired($client);
            $anchor = (int) ($lapsed ? substr($start,8,2) : ($client['billing_anchor_day'] ?: substr($start,8,2)));
            $end = self::periodEnd($start, (string)$client['billing_period'], $anchor);
            $cycle = $client['id'] . ':' . $start;
            $previous = Db::row('SELECT * FROM billing_invoices WHERE cycle_key=? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$cycle]);
            if (!$previous) {
                $legacyCycles = Db::select('SELECT * FROM billing_invoices WHERE username=? AND period_start=? AND cycle_class="generated" AND cycle_key IS NULL ORDER BY id FOR UPDATE', [$username,$start]);
                if (count($legacyCycles) > 1) { throw new RuntimeException('Multiple legacy cycles require reviewed reconciliation before replacement.'); }
                $previous = $legacyCycles[0] ?? null;
            }
            if ($previous && $previous['status'] !== 'void') {
                $pdo->commit();SubscriberPolicy::releaseLocks(); return null;
            }
            if ($previous) {
                if (empty($previous['billing_anchor_day'])) { throw new RuntimeException('Voided cycle anchor requires reviewed classification before replacement.'); }
                if ($previous['period_start'] !== $start || empty($previous['period_end']) || $previous['period_end'] < $start) {
                    throw new RuntimeException('Voided cycle has unresolved sold dates; replacement requires reviewed evidence.');
                }
                BillingCalendar::date($previous['period_end']);
                $anchor = (int)$previous['billing_anchor_day'];
                $end = $previous['period_end'];
            }
            // Unknown history requires a decision, not a guess based on notes or tuple equality.
            $serviceGroup = $previous['service_group'] ?? $client['service_group'];
            $legacy = Db::row('SELECT id FROM billing_invoices WHERE username=? AND service_group <=> ? AND cycle_key IS NULL AND cycle_class IS NULL AND status<>"draft"
                AND ((status NOT IN ("paid","void") AND (period_start IS NULL OR period_end IS NULL OR (period_start<=? AND period_end>=?)))
                    OR (period_start=? AND (status="void" OR period_end=?))) ORDER BY id LIMIT 1 FOR UPDATE',
                [$username,$serviceGroup,$end,$start,$start,$end]);
            if ($legacy) { throw new RuntimeException('Overlapping legacy invoice '.$legacy['id'].' requires reviewed per-invoice classification.'); }
            $overlap = Db::row('SELECT id FROM billing_invoices WHERE username=? AND service_group <=> ? AND status NOT IN ("void","draft")
                AND (cycle_key IS NOT NULL OR cycle_class="generated")
                AND (period_start IS NULL OR period_end IS NULL OR (period_start<=? AND period_end>=?)) ORDER BY id LIMIT 1 FOR UPDATE',
                [$username,$serviceGroup,$end,$start]);
            if ($overlap) {
                $pdo->commit();SubscriberPolicy::releaseLocks(); return null;
            }
            Db::exec('UPDATE customer_profiles SET billing_anchor_day=? WHERE username=?', [$anchor,$username]);
            $id = self::saveInvoice([
                'username'=>$username, 'service_group'=>$serviceGroup, 'billing_period'=>$previous['billing_period'] ?? $client['billing_period'],
                'static_ip_pool_key'=>self::staticIpPoolKeyForCustomer($username) ?? '',
                'issue_date'=>Entitlement::today(), 'period_start'=>$start, 'anchor_day'=>$anchor,
                '_cycle_key'=>$cycle, '_period_end'=>$end, '_replaces_invoice_id'=>$previous['id'] ?? null,
                'status'=>'open', 'notes'=>'Generated from service plan.',
            ]);
            $pdo->commit();SubscriberPolicy::releaseLocks(); return $id;
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } throw $e; }
    }

    public static function generateRecurringInvoices(?string $onlyUsername = null): array
    {
        SchemaCompatibility::require('billing');
        self::markOverdueInvoices();
        $params = [];
        $where = 'WHERE c.enabled = 1 AND c.account_status IN ("active", "expired") AND s.enabled = 1 AND s.price > 0';
        if ($onlyUsername) {
            $where .= ' AND c.username = ?';
            $params[] = $onlyUsername;
        }
        $clients = Db::select(
            'SELECT c.username, c.expiry_date, c.service_group
             FROM customer_profiles c
             JOIN service_metadata s ON s.group_name = c.service_group ' . $where,
            $params
        );

        $created = 0;
        $skipped = 0;
        foreach ($clients as $client) {
            $id = self::createServiceInvoice((string) $client['username']);
            $id ? $created++ : $skipped++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'checked' => count($clients)];
    }

    /** Record an explicit operator review; never infer historical origin, keys or anchors. */
    public static function classifyLegacyInvoice(int $invoiceId, string $classification, string $evidence, ?int $anchor = null): void
    {
        SchemaCompatibility::require('billing');
        if (!in_array($classification, ['manual','generated'], true) || trim($evidence) === '' || ($anchor !== null && ($anchor < 1 || $anchor > 31))) {
            throw new RuntimeException('Classification requires review evidence and a valid optional sold anchor.');
        }
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $identity = Db::row('SELECT username FROM billing_invoices WHERE id=?', [$invoiceId]);
            if (!$identity) { throw new RuntimeException('Invoice not found.'); }
            Db::row('SELECT username FROM customer_profiles WHERE username=? FOR UPDATE', [$identity['username']]);
            $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=? FOR UPDATE', [$invoiceId]);
            if (!$invoice || $invoice['username'] !== $identity['username']
                || ($invoice['cycle_class'] !== null && ($invoice['cycle_class'] !== $classification || $invoice['billing_anchor_day'] !== null || $anchor === null))
                || (!empty($invoice['cycle_key']) && $classification !== 'generated')) {
                throw new RuntimeException('Only unresolved legacy classification or anchor can be reviewed.');
            }
            if ($invoice['billing_anchor_day'] !== null) {
                if ($anchor !== null && $anchor !== (int)$invoice['billing_anchor_day']) { throw new RuntimeException('Known sold anchors cannot be changed.'); }
                $anchor = (int)$invoice['billing_anchor_day'];
            }
            Db::exec('UPDATE billing_invoices SET cycle_class=?,billing_anchor_day=? WHERE id=?', [$classification,$anchor,$invoiceId]);
            Audit::log('classify', 'billing_invoice', (string)$invoiceId, ['cycle_class'=>$invoice['cycle_class'],'billing_anchor_day'=>$invoice['billing_anchor_day']],
                ['cycle_class'=>$classification,'billing_anchor_day'=>$anchor,'evidence'=>trim($evidence)]);
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } throw $e; }
    }

    public static function recordPayment(int $invoiceId, array $input): int
    {
        SchemaCompatibility::require('billing');
        $amount = self::decimal($input['amount'] ?? 0);
        if ($amount <= 0) { throw new RuntimeException('Payment amount must be greater than zero.'); }
        $key = (string) ($input['request_key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $key)) { throw new RuntimeException('A payment request key is required.'); }
        $method = (string) ($input['method'] ?? 'cash');
        if (!in_array($method, ['cash','bank_transfer','mobile_money','card','check','other'], true)) { throw new RuntimeException('Invalid payment method.'); }
        $date = self::dateOrToday($input['payment_date'] ?? null);
        $reference = trim((string) ($input['reference'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $hashDate=trim((string)($input['payment_date'] ?? ''))===''?'':$date;
        $hash = hash('sha256', json_encode([$invoiceId, number_format($amount,2,'.',''), $method, $hashDate, $reference, $notes], JSON_THROW_ON_ERROR));
        $actor = Auth::user();
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $identity = Db::row('SELECT username FROM billing_invoices WHERE id=?', [$invoiceId]);
            if (!$identity) { throw new RuntimeException('Invoice not found.'); }
            Db::row('SELECT username FROM customer_profiles WHERE username=? FOR UPDATE', [$identity['username']]);
            $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=? FOR UPDATE', [$invoiceId]);
            if(isset($input['currency']) && (!$invoice || $input['currency']!==$invoice['currency'])){throw new RuntimeException('Payment currency must match invoice.');}
            $existing = Db::row('SELECT * FROM billing_payments WHERE request_key=? FOR UPDATE', [$key]);
            if ($existing) {
                if (!hash_equals((string)$existing['request_hash'], $hash)) { throw new RuntimeException('Payment request key was reused with different details.'); }
                $pdo->commit();SubscriberPolicy::releaseLocks(); return (int)$existing['id'];
            }
            if (!$invoice || in_array($invoice['status'], ['void','draft'], true) || $invoice['username'] !== $identity['username']) { throw new RuntimeException('Invoice not found, changed, draft or void.'); }
            if (isset($input['currency']) && $input['currency'] !== $invoice['currency']) { throw new RuntimeException('Payment currency must match invoice.'); }
            $balance = round((float)$invoice['total_amount'] - self::invoicePaidAmount($invoiceId),2);
            if ($amount > $balance) { throw new RuntimeException('Overpayments require a credits policy and are not accepted.'); }
            Db::exec('INSERT INTO billing_payments (invoice_id,username,payment_date,amount,currency,method,reference,notes,received_by,created_at,request_key,request_hash) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,?)',
                [$invoiceId,$invoice['username'],$date,$amount,$invoice['currency'],$method,$reference,$notes,$actor['id'] ?? null,$key,$hash]);
            $paymentId = (int)$pdo->lastInsertId();
            self::refreshInvoice($invoiceId);
            self::refreshCustomerBalance((string)$invoice['username']);
            $updated = Db::row('SELECT * FROM billing_invoices WHERE id=?', [$invoiceId]);
            if ($updated['status'] === 'paid') { self::renewCustomerFromInvoice($updated); }
            Audit::log('payment', 'billing_invoice', (string)$invoiceId, null, ['payment_id'=>$paymentId,'amount'=>number_format($amount,2,'.','')]);
            $pdo->commit();SubscriberPolicy::releaseLocks(); return $paymentId;
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } throw $e; }
    }

    public static function voidInvoice(int $invoiceId): void
    {
        SchemaCompatibility::require('billing');
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $identity = Db::row('SELECT username FROM billing_invoices WHERE id=?', [$invoiceId]);
            if (!$identity) { throw new RuntimeException('Invoice not found.'); }
            Db::row('SELECT username FROM customer_profiles WHERE username=? FOR UPDATE', [$identity['username']]);
            $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=? FOR UPDATE', [$invoiceId]);
            if (!$invoice || $invoice['username'] !== $identity['username']) { throw new RuntimeException('Invoice changed.'); }
            if (self::invoicePaidAmount($invoiceId) > 0) { throw new RuntimeException('Payment-bearing invoices cannot be voided without a credits/refunds policy.'); }
            Db::exec('UPDATE billing_invoices SET status="void", balance_due=0, updated_at=NOW() WHERE id=?', [$invoiceId]);
            self::refreshCustomerBalance((string)$invoice['username']);
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } throw $e; }
    }

    public static function deleteInvoice(int $invoiceId): void
    {
        SchemaCompatibility::require('billing');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $identity=Db::row('SELECT username FROM billing_invoices WHERE id=?',[$invoiceId]);
            if(!$identity){throw new RuntimeException('Invoice not found.');}
            Db::row('SELECT username FROM customer_profiles WHERE username=? FOR UPDATE',[$identity['username']]);
            $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=? FOR UPDATE', [$invoiceId]);
            if (!$invoice || $invoice['username']!==$identity['username']) { throw new RuntimeException('Invoice changed.'); }
            if ($invoice['status'] !== 'draft' || !empty($invoice['cycle_key']) || (float) $invoice['amount_paid'] > 0
                || (int) Db::value('SELECT COUNT(*) FROM billing_payments WHERE invoice_id=?', [$invoiceId]) > 0) {
                throw new RuntimeException('Only draft invoices without payments can be deleted. Issued invoices and payment history must be retained.');
            }
            Db::exec('DELETE FROM billing_invoice_items WHERE invoice_id=?', [$invoiceId]);
            Db::exec('DELETE FROM billing_invoices WHERE id=?', [$invoiceId]);
            self::refreshCustomerBalance((string) $invoice['username']);
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } catch (\Throwable) { /* Preserve the original failure. */ }
            }
            throw $e;
        }
    }

    public static function markOverdueInvoices(): int
    {
        SchemaCompatibility::require('billing');
        $changed=0;
        foreach(Db::select('SELECT DISTINCT username FROM billing_invoices WHERE status IN ("open","partial","overdue") ORDER BY username') as $row){
            $pdo=Db::pdo();$pdo->beginTransaction();
            try{
                Db::row('SELECT username FROM customer_profiles WHERE username=? FOR UPDATE',[$row['username']]);
                $changed+=Db::exec('UPDATE billing_invoices SET status="overdue",updated_at=NOW() WHERE username=? AND status IN ("open","partial") AND balance_due>0 AND due_date<?',[$row['username'],Entitlement::today()]);
                self::refreshCustomerBalance((string)$row['username']);$pdo->commit();SubscriberPolicy::releaseLocks();
            }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();SubscriberPolicy::releaseLocks();}throw $e;}
        }
        return $changed;
    }

    public static function invoice(int $id): ?array
    {
        return Db::row(
            'SELECT i.*, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.expiry_date, s.name AS service_name
             FROM billing_invoices i
             JOIN customer_profiles c ON c.username = i.username
             LEFT JOIN service_metadata s ON s.group_name = i.service_group
             WHERE i.id = ?',
            [$id]
        );
    }

    /** Derive receipt claims from payment evidence rather than a cached status/paid total. */
    public static function receiptSummary(array $invoice, array $payments): array
    {
        if (in_array($invoice['status'], ['draft', 'void'], true) || !$payments) {
            throw new RuntimeException('A receipt requires a non-void invoice with recorded payments.');
        }
        $paidCents = 0;
        foreach ($payments as $payment) {
            $amount = (int) round((float) $payment['amount'] * 100);
            if ($amount <= 0 || $payment['currency'] !== $invoice['currency']
                || $payment['username'] !== $invoice['username'] || (int) $payment['invoice_id'] !== (int) $invoice['id']) {
                throw new RuntimeException('Payment evidence does not match this invoice.');
            }
            $paidCents += $amount;
        }
        $balanceCents = max(0, (int) round((float) $invoice['total_amount'] * 100) - $paidCents);
        return [
            'state' => $balanceCents === 0 ? 'paid' : 'partial',
            'title' => $balanceCents === 0 ? 'PAYMENT RECEIPT' : 'PARTIAL PAYMENT RECEIPT',
            'amount_paid' => number_format($paidCents / 100, 2, '.', ''),
            'balance_due' => number_format($balanceCents / 100, 2, '.', ''),
        ];
    }

    public static function invoiceItems(int $invoiceId): array
    {
        return Db::select('SELECT * FROM billing_invoice_items WHERE invoice_id=? ORDER BY id', [$invoiceId]);
    }

    public static function invoicePayments(int $invoiceId): array
    {
        return Db::select('SELECT p.*, u.username AS receiver FROM billing_payments p LEFT JOIN portal_users u ON u.id=p.received_by WHERE p.invoice_id=? ORDER BY p.payment_date DESC, p.id DESC', [$invoiceId]);
    }

    public static function refreshCustomerBalance(string $username): void
    {
        $totals=Db::row('SELECT COALESCE(SUM(CASE WHEN status NOT IN ("paid","void","draft") THEN balance_due ELSE 0 END),0) AS balance,
            COALESCE(SUM(status="overdue" AND balance_due>0),0) AS overdue,
            COALESCE(SUM(status IN ("open","partial") AND balance_due>0),0) AS open_count
            FROM billing_invoices WHERE username=?',[$username]);
        $balance=(float)$totals['balance'];
        $paymentStatus=(int)$totals['overdue']>0?'overdue':((int)$totals['open_count']>0?'due':'current');
        Db::exec(
            'UPDATE customer_profiles SET balance=?, invoice_alert=?, payment_status=?, updated_at=NOW() WHERE username=?',
            [$balance, $balance > 0 ? 1 : 0, $paymentStatus, $username]
        );
    }

    private static function refreshInvoice(int $invoiceId): void
    {
        $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=?', [$invoiceId]);
        if (!$invoice) {
            return;
        }
        $paid = self::invoicePaidAmount($invoiceId);
        $total = (float) $invoice['total_amount'];
        $balance = max(0, round($total - $paid, 2));
        $status = self::statusForBalance((string) $invoice['status'], $total, $paid, (string) $invoice['due_date']);
        Db::exec(
            'UPDATE billing_invoices SET amount_paid=?, balance_due=?, status=?, updated_at=NOW() WHERE id=?',
            [$paid, $balance, $status, $invoiceId]
        );
    }

    private static function renewCustomerFromInvoice(array $invoice): void
    {
        if (empty($invoice['period_end']) || empty($invoice['username'])) {
            return;
        }
        $username = (string) $invoice['username'];
        $customer = Db::row('SELECT * FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
        if (!$customer || $customer['account_status'] === 'archived') { return; }
        // Settling an older sold period must not replace a later entitlement or selected plan.
        if (!empty($customer['expiry_date']) && $invoice['period_end'] < $customer['expiry_date']) { return; }
        if (!empty($invoice['billing_anchor_day'])) {
            Db::exec('UPDATE customer_profiles SET billing_anchor_day=? WHERE username=?',[(int)$invoice['billing_anchor_day'],$username]);
        }
        $serviceGroup = self::blankToNull($invoice['service_group'] ?? null);
        if ($serviceGroup !== null) {
            self::assignCustomerService($username, $serviceGroup);
        }
        Db::exec(
            'UPDATE customer_profiles
             SET expiry_date=GREATEST(COALESCE(expiry_date, "1000-01-01"), ?), updated_at=NOW()
             WHERE username=?',
            [$invoice['period_end'], $username]
        );
        RadiusRepository::provisionClient($username);
        CustomerNotifications::extended((int)$customer['id'], $customer['expiry_date'], $invoice['period_end']);
    }

    private static function assignCustomerService(string $username, string $serviceGroup): void
    {
        $service = Db::row(
            'SELECT group_name, billing_mode FROM service_metadata WHERE group_name=? AND enabled=1',
            [$serviceGroup]
        );
        if (!$service) {
            throw new RuntimeException('Select an enabled service plan.');
        }

        Db::exec(
            'UPDATE customer_profiles
             SET service_group=?, billing_mode=?, updated_at=NOW()
             WHERE username=?',
            [
                $service['group_name'],
                $service['billing_mode'] ?: 'prepaid',
                $username,
            ]
        );
    }

    private static function staticIpChargeForInvoice(string $username, ?string $poolKey): ?array
    {
        if ($poolKey === null) {
            return null;
        }
        if ($poolKey === '__assigned') {
            $poolKey = self::staticIpPoolKeyForCustomer($username);
        }
        if ($poolKey === null || $poolKey === '') {
            return null;
        }

        $pool = Db::row(
            'SELECT pool_key, name, ip_ranges, price, currency FROM static_ip_pools WHERE pool_key=? AND enabled=1 AND price > 0 FOR UPDATE',
            [$poolKey]
        );
        if (!$pool) {
            throw new RuntimeException('Select a priced static IP pool, or choose no static IP charge.');
        }

        $ip = trim((string) (Db::value('SELECT framed_ip_address FROM customer_profiles WHERE username=?', [$username]) ?: ''));
        if ($ip === '' || !in_array($ip, self::staticIpPoolList((string) $pool['ip_ranges']), true)) {
            throw new RuntimeException('Static IP charges must match the allocated customer address.');
        }
        StaticIpPool::poolFor($ip, Db::select('SELECT * FROM static_ip_pools WHERE enabled=1'));

        return [
            'ip' => $ip,
            'name' => (string) ($pool['name'] ?: $pool['pool_key']),
            'price' => $pool['price'],
            'currency' => strtoupper((string) ($pool['currency'] ?: 'USD')),
        ];
    }

    private static function staticIpPoolKeyForCustomer(string $username): ?string
    {
        $ip = trim((string) (Db::value('SELECT framed_ip_address FROM customer_profiles WHERE username=?', [$username]) ?: ''));
        if ($ip === '') {
            return null;
        }

        $pool=StaticIpPool::poolFor($ip,Db::select('SELECT * FROM static_ip_pools WHERE enabled=1'));
        return $pool && (float)$pool['price']>0 ? (string)$pool['pool_key'] : null;
    }

    private static function staticIpDescription(array $charge): string
    {
        $ip = trim((string) ($charge['ip'] ?? ''));
        return $ip !== ''
            ? 'Static IP address ' . $ip . ' (' . $charge['name'] . ')'
            : 'Static IP service (' . $charge['name'] . ')';
    }

    private static function invoicePaidAmount(int $invoiceId): float
    {
        return (float) Db::value('SELECT COALESCE(SUM(amount), 0) FROM billing_payments WHERE invoice_id=?', [$invoiceId]);
    }

    private static function statusForBalance(string $current, float $total, float $paid, string $dueDate): string
    {
        if (in_array($current, ['void', 'draft'], true)) { return $current; }
        if ($paid >= $total && $total >= 0) {
            return 'paid';
        }
        if ($paid > 0) {
            return $dueDate < Entitlement::today() ? 'overdue' : 'partial';
        }
        if ($current === 'draft') {
            return 'draft';
        }
        return $dueDate < Entitlement::today() ? 'overdue' : 'open';
    }

    private static function servicePeriod(array $client, ?string $periodStart): array
    {
        $start = self::dateOrToday($periodStart ?: self::nextPeriodStart($client));
        $end = self::periodEnd($start, (string) ($client['billing_period'] ?? 'monthly'));
        return ['start' => $start, 'end' => $end, 'due' => $end];
    }

    private static function periodEnd(string $start, string $period, ?int $anchor = null): string
    {
        return BillingCalendar::end($start,$period,$anchor);
    }

    private static function billingPeriodMonths(string $period): int
    {
        return BillingCalendar::months($period);
    }

    private static function normalizeBillingPeriod(string $period): string
    {
        return in_array($period, ['monthly', '2_months', '3_months', '6_months', 'yearly'], true) ? $period : 'monthly';
    }

    private static function nextPeriodStart(array $client): string
    {
        $expiry = trim((string) ($client['expiry_date'] ?? ''));
        if ($expiry !== '' && $expiry >= Entitlement::today()) {
            return date('Y-m-d', strtotime($expiry . ' +1 day'));
        }
        return Entitlement::today();
    }

    private static function hasServiceInvoice(string $username, string $serviceGroup, string $start, string $end): bool
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM billing_invoices
             WHERE username=? AND service_group=? AND period_start=? AND period_end=? AND status <> "void"',
            [$username, $serviceGroup, $start, $end]
        ) > 0;
    }

    private static function invoiceNumber(int $id): string
    {
        return 'INV-' . date('Ym') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private static function decimal(mixed $value): float
    {
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', trim((string)$value))) { throw new RuntimeException('Invalid monetary amount.'); }
        return round((float)$value, 2);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function dateOrToday(mixed $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? Entitlement::today() : BillingCalendar::date($value)->format('Y-m-d');
    }

    private static function staticIpPoolList(string $text): array
    {
        return StaticIpPool::parse($text);
    }
}
