<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Currency;
use App\Support\Db;
use Tests\TestCase;

final class BillingTest extends TestCase
{
    private function customer(string $username = 'alice'): void
    {
        Currency::change('EUR');
        $this->post('/services/save', [
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'custom_group_attrs' => '',
        ]);
        $this->post('/clients/save', [
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => $username, 'password' => 'pppoe-pass-1', 'verified' => '0',
            'account_status' => 'enabled', 'expiry_date' => date('Y-m-d', strtotime('+30 days')), 'service_group' => 'home50',
            'framed_ip_address' => '', 'framed_pool' => '', 'nas_restriction_type' => 'all', 'nas_restriction_value' => '', 'language' => 'en',
            'first_name' => 'Alice', 'last_name' => 'Doe', '_active_tab' => 'account',
        ])->assertSessionHas('flash.type', 'success');
    }

    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id' => '', 'username' => 'alice', 'service_group' => 'home50', 'static_ip_pool_key' => '', 'billing_period' => 'monthly',
            'description' => 'Home 50 service', 'currency' => 'EUR', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'issue_date' => date('Y-m-d'), 'status' => 'open', 'notes' => '',
        ], $overrides);
    }

    private function savedInvoiceId(): int
    {
        return (int) Db::value('SELECT MAX(id) FROM billing_invoices');
    }

    public function test_issue_list_export_and_view_an_invoice(): void
    {
        $this->actingAsOperator();
        $this->customer();
        $this->get('/billing/invoices/create?username=alice')->assertOk()->assertInertia(fn ($page) => $page
            ->component('billing/Form')
            ->where('values.username', 'alice')
            ->where('values.service_group', 'home50')
            ->where('values.currency', 'EUR')
            ->where('values.description', 'Home 50 service')
            ->where('services.0.price', 20));

        $this->post('/billing/invoices/save', $this->invoice())->assertSessionHas('flash.type', 'success');
        $id = $this->savedInvoiceId();
        $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=?', [$id]);
        $this->assertSame('open', $invoice['status']);
        $this->assertSame('20.00', (string) $invoice['total_amount']);

        $this->get('/billing?q=alice')->assertOk()->assertInertia(fn ($page) => $page
            ->component('billing/Index')
            ->where('result.total', 1)
            ->where('result.rows.0.customer', 'Alice Doe')
            ->where('result.rows.0.status.label', 'Unpaid')
            ->where('result.rows.0.canVoid', true)
            ->where('result.rows.0.canDelete', false)
            ->where('metrics.0.value', '20.00 EUR'));
        $this->get('/billing?status=paid')->assertOk()->assertInertia(fn ($page) => $page->where('result.total', 0));
        $this->get('/billing?status=unpaid')->assertOk()->assertInertia(fn ($page) => $page
            ->where('result.total', 1)
            ->where('result.rows.0.owed', true)
            ->where('statuses.1.label', 'To collect')
            ->where('statuses.1.count', 1)
            ->where('payments', []));

        $csv = $this->get('/billing/export?q=alice')->assertOk()->streamedContent();
        $this->assertStringContainsString('alice,"Alice Doe"', $csv);

        $this->get('/billing/invoices/' . $id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('billing/Show')
            ->where('invoice.banner.tone', 'warning')
            ->where('invoice.receipt', null)
            ->where('payment.recorded', null)
            ->where('payment.values.amount', '20.00')
            ->where('canEdit', false)
            ->where('invoice.customer', 'Alice Doe')
            // No discount or tax: only the total and the balance due.
            ->where('invoice.totals', [['Total', '20.00 EUR', 'total'], ['Balance due', '20.00 EUR', 'balance']])
            ->has('items', 1));

        $pdf = $this->get('/billing/invoices/' . $id . '/draft-pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->get('/billing/invoices/' . $id . '/edit')->assertRedirect('/billing/invoices/' . $id)->assertSessionHas('flash.message', 'Issued invoices cannot be edited.');
        $this->get('/billing/invoices/999999')->assertNotFound();

        $this->post('/billing/invoices/' . $id . '/void')->assertRedirect('/billing/invoices/' . $id)->assertSessionHas('flash.type', 'success');
        $this->get('/billing/invoices/' . $id)->assertInertia(fn ($page) => $page->where('invoice.banner.title', 'Cancelled')->where('invoice.draftPdf', false)->where('canVoid', false));
    }

    public function test_payment_is_recorded_once_and_downloads_a_receipt(): void
    {
        $this->actingAsOperator();
        $this->customer();
        $this->post('/billing/invoices/save', $this->invoice());
        $id = $this->savedInvoiceId();
        $key = '';
        $this->get('/billing/invoices/' . $id)->assertInertia(function ($page) use (&$key) {
            $key = $page->toArray()['props']['payment']['key'];
        });

        $payment = ['request_key' => $key, 'payment_date' => date('Y-m-d'), 'amount' => '5.00', 'method' => 'cash', 'reference' => 'R-1', 'notes' => ''];
        $this->post('/billing/invoices/' . $id . '/payment', $payment)
            ->assertRedirect('/billing/invoices/' . $id)
            ->assertSessionHas('download', '/billing/invoices/' . $id . '/payment-pdf');
        // A double submit with the same key is the same payment.
        $this->post('/billing/invoices/' . $id . '/payment', $payment)->assertSessionHas('flash.type', 'success');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM billing_payments WHERE invoice_id=?', [$id]));
        $this->assertSame('partial', Db::value('SELECT status FROM billing_invoices WHERE id=?', [$id]));

        $this->get('/billing/invoices/' . $id)->assertInertia(fn ($page) => $page
            ->where('payment.recorded.amount', '5.00 EUR')
            ->where('invoice.receipt.label', 'Partial payment receipt')
            ->where('canVoid', false)
            ->has('payments', 1));
        $this->get('/billing/invoices/' . $id . '/payment-pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->post('/billing/invoices/' . $id . '/payment', ['request_key' => 'stale-key-0000000000'] + $payment)
            ->assertSessionHas('flash.message', 'Payment form changed. Review recorded payments and reload before continuing.');
        $this->post('/billing/invoices/' . $id . '/payment/new', ['previous_request_key' => $key])->assertRedirect('/billing/invoices/' . $id);
        $this->get('/billing/invoices/' . $id)->assertInertia(fn ($page) => $page
            ->where('payment.recorded', null)
            ->where('payment.values.amount', '15.00')
            ->where('payment.key', fn ($next) => $next !== $key));

        $this->post('/billing/invoices/' . $id . '/void')->assertSessionHas('flash.message', 'Payment-bearing invoices cannot be voided without a credits/refunds policy.');
        $this->assertSame('partial', Db::value('SELECT status FROM billing_invoices WHERE id=?', [$id]));
    }

    public function test_drafts_edit_delete_validation_and_generation(): void
    {
        $this->actingAsOperator();
        $this->customer();
        $this->post('/billing/invoices/save', $this->invoice(['status' => 'draft', 'discount_amount' => '-1']))
            ->assertRedirect('/billing/invoices/create?username=alice')
            ->assertSessionHasErrors('discount_amount');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_invoices'));

        $this->post('/billing/invoices/save', $this->invoice(['status' => 'draft']));
        $id = $this->savedInvoiceId();
        $this->get('/billing/invoices/' . $id . '/edit')->assertOk()->assertInertia(fn ($page) => $page->where('values.id', $id)->where('values.status', 'draft'));
        $this->get('/billing/invoices/' . $id)->assertInertia(fn ($page) => $page->where('payment', null)->where('canEdit', true)->where('canDelete', true));
        $this->post('/billing/invoices/save', $this->invoice(['id' => (string) $id, 'status' => 'draft', 'tax_amount' => '2.00']));
        $this->assertSame('22.00', (string) Db::value('SELECT total_amount FROM billing_invoices WHERE id=?', [$id]));
        $this->post('/billing/invoices/' . $id . '/delete')->assertRedirect('/billing');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_invoices'));

        $this->post('/billing/generate')->assertRedirect('/billing')->assertSessionHas('flash.type', 'success');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM billing_invoices WHERE username="alice"'));
    }

    public function test_a_customer_needing_review_does_not_stop_billing_for_the_others(): void
    {
        $this->actingAsOperator();
        $this->customer('alice');
        $this->post('/billing/generate');
        // A voided generated cycle without its anchor day needs an operator decision before it is replaced.
        Db::exec('UPDATE billing_invoices SET status="void", billing_anchor_day=NULL WHERE username="alice"');
        $this->customer('bob');

        $this->post('/billing/generate')->assertSessionHas('flash.message', fn (string $m) => str_contains($m, '1 customer(s) need review'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM billing_invoices WHERE username="bob"'));
        $this->get('/billing')->assertInertia(fn ($page) => $page
            ->where('lastRun.tone', 'warning')
            ->where('lastRun.review.0.username', 'alice')
            ->where('lastRun.review.0.reason', 'Voided cycle anchor requires reviewed classification before replacement.'));
    }

    public function test_view_role_is_read_only(): void
    {
        $this->actingAsOperator();
        $this->customer();
        $this->post('/billing/invoices/save', $this->invoice());
        $id = $this->savedInvoiceId();

        $this->actingAsOperator('view');
        $this->get('/billing')->assertOk()->assertInertia(fn ($page) => $page->where('result.rows.0.canVoid', false));
        $this->get('/billing/invoices/' . $id)->assertOk()->assertInertia(fn ($page) => $page->where('payment', null)->where('classify', null));
        $this->get('/billing/invoices/create')->assertForbidden();
        $this->post('/billing/invoices/save', $this->invoice())->assertForbidden();
        $this->post('/billing/invoices/' . $id . '/void')->assertForbidden();
        $this->post('/billing/generate')->assertForbidden();

        $this->actingAsOperator('support');
        $this->get('/billing')->assertForbidden();
    }
}
