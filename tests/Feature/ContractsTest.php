<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminAlerts;
use App\Support\Contracts;
use App\Support\Currency;
use App\Support\Db;
use App\Support\License;
use App\Support\PayPage;
use App\Support\Settings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ContractsTest extends TestCase
{
    private const PAY = 'https://pay.radiusone.test';

    private const PORTAL = 'https://radiusone.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsOperator();
        Currency::change('EUR');
        $this->post('/services/save', [
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true,
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'billing_period' => 'monthly',
            'download_mbps' => '50', 'upload_mbps' => '10',
        ]);
        $this->post('/clients/save', [
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => 'alice', 'password' => 'pppoe-pass-123', 'account_status' => 'enabled',
            'expiry_date' => date('Y-m-d', strtotime('+5 days')), 'service_group' => 'home50', 'framed_ip_address' => '', 'framed_pool' => '',
            'nas_restriction_type' => 'all', 'nas_restriction_value' => '', 'first_name' => 'Alice', 'last_name' => 'Doe', 'phone' => '+355 69 123 4567', 'online_pay' => '1', '_active_tab' => 'account',
        ])->assertSessionHas('flash.type', 'success');
    }

    private function contract(int $term = 12, string $period = 'monthly', bool $invoice = false): int
    {
        return Contracts::create(['username' => 'alice', 'term_months' => $term, 'billing_period' => $period, 'issue_invoice' => $invoice]);
    }

    private function enablePay(): void
    {
        Settings::save('pay_enabled', '1');
        Settings::save('pay_host', 'pay.radiusone.test');
        Settings::save('pok_enabled', '1');
        Settings::save('pok_mode', 'live');
        Settings::save('pok_live_merchant_id', 'merchant-1');
        Settings::save('pok_live_key_id', 'key-1');
        Settings::save('pok_live_key_secret', 'secret-1', true);
    }

    /** POK as a fake: the order it reports is $order. */
    private function fakePok(array $order): void
    {
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok']]),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'order-12345', 'expiresAt' => date('c', time() + 3600), '_self' => ['confirmUrl' => 'https://pok.test/pay/order-12345']]]]),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => ['id' => 'order-12345'] + $order]]),
        ]);
    }

    private function startPayment(string $period = 'monthly', string $provider = 'pok'): array
    {
        $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567']);
        $this->post(self::PAY . '/pay/start', ['period' => $period, 'provider' => $provider], ['X-Inertia' => 'true']);
        return Db::row('SELECT * FROM pay_payments ORDER BY id DESC LIMIT 1');
    }

    public function test_issuing_a_contract_stores_its_text_and_first_invoice(): void
    {
        Settings::save('contract_template', 'Hello {customer}, {plan} until {end_date}. No. {contract_number}');
        $id = $this->contract(12, '3_months', true);
        $row = Db::row('SELECT * FROM customer_contracts WHERE id=?', [$id]);
        $this->assertSame('active', $row['status']);
        $this->assertStringContainsString('Hello Alice Doe, Home 50 until ' . $row['end_date'], $row['body']);
        $this->assertStringContainsString($row['contract_number'], $row['body']);
        $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=?', [$row['first_invoice_id']]);
        $this->assertSame('3_months', $invoice['billing_period']);
        $this->assertSame('60.00', $invoice['total_amount']);
        $client = Db::row('SELECT contract_id, contract_valid_until FROM customer_profiles WHERE username="alice"');
        $this->assertSame($row['contract_number'], $client['contract_id']);
        $this->assertSame($row['end_date'], $client['contract_valid_until']);

        // A later change of template leaves the issued contract alone.
        Contracts::saveTemplate('Something else');
        $this->assertStringContainsString('Hello Alice', (string) Db::value('SELECT body FROM customer_contracts WHERE id=?', [$id]));
    }

    public function test_rules_on_term_period_and_a_second_contract(): void
    {
        $this->post('/contracts/save', ['username' => 'alice', 'term_months' => '1', 'billing_period' => '3_months', 'issue_invoice' => false])
            ->assertSessionHasErrors();
        $this->contract();
        $this->post('/contracts/save', ['username' => 'alice', 'term_months' => '12', 'billing_period' => 'monthly', 'issue_invoice' => false])
            ->assertSessionHasErrors();
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM customer_contracts'));
    }

    public function test_term_end_is_the_day_before_the_same_date(): void
    {
        $this->assertSame('2027-01-04', Contracts::termEnd('2026-01-05', 12));
        $this->assertSame('2026-02-27', Contracts::termEnd('2026-01-31', 1));
    }

    public function test_the_template_refuses_unknown_placeholders_and_pdf_paginates(): void
    {
        $this->post('/settings/contracts', ['template' => 'Hi {nope}'])->assertSessionHasErrors();
        $this->post('/settings/contracts', ['template' => "# Heading\n" . str_repeat("A line about the service, with ë and ç.\n", 120)])->assertSessionHas('flash.type', 'success');
        $id = $this->contract();
        $pdf = $this->get("/contracts/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page '));
    }

    public function test_contracts_need_a_licence(): void
    {
        License::save('');
        $this->get('/contracts')->assertInertia(fn ($page) => $page->component('Pro'));
    }

    public function test_the_pay_page_and_the_portal_are_kept_apart(): void
    {
        $this->enablePay();
        $this->get('/pay')->assertNotFound();
        $this->get(self::PAY . '/')->assertRedirect('/pay');
        $this->get(self::PAY . '/pay')->assertOk();
        foreach (['/login', '/clients', '/settings', '/billing', '/contracts'] as $path) {
            $this->get(self::PAY . $path)->assertNotFound();
        }
        Settings::save('pay_enabled', '0');
        $this->get(self::PAY . '/pay')->assertNotFound();
    }

    public function test_lookup_shows_little_and_says_the_same_for_unknown_and_no_contract(): void
    {
        $this->enablePay();
        $none = $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567'])->getSession()->get('flash.message');
        $unknown = $this->post(self::PAY . '/pay/lookup', ['username' => 'nobody', 'phone' => '4567'])->getSession()->get('flash.message');
        $this->assertSame($none, $unknown);

        $this->contract(3);
        $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567']);
        $response = $this->get(self::PAY . '/pay');
        $response->assertInertia(fn ($page) => $page->component('pay/Pay')
            ->where('account.customer', 'A**** D**')
            ->where('account.service', 'Home 50')
            ->missing('account.email'));
        $this->assertStringNotContainsString('secret-1', $response->getContent());
    }

    public function test_only_periods_inside_the_contract_are_offered(): void
    {
        $this->enablePay();
        $this->contract(3);
        $account = PayPage::account('alice');
        $this->assertSame(['monthly'], array_column($account['options'], 'period'));
        foreach ($account['options'] as $option) {
            $this->assertLessThanOrEqual($account['contract']['end_date'], $option['end']);
        }
        $this->fakePok([]);
        $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567']);
        $this->post(self::PAY . '/pay/start', ['period' => 'yearly', 'provider' => 'pok']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM pay_payments'));
    }

    public function test_a_completed_payment_issues_the_invoice_and_renews(): void
    {
        $this->enablePay();
        $this->contract(12);
        $before = (string) Db::value('SELECT expiry_date FROM customer_profiles WHERE username="alice"');
        $this->fakePok(['isCompleted' => true, 'originalAmount' => 60, 'originalCurrencyCode' => 'EUR', 'transactionId' => 'tx-1']);
        $payment = $this->startPayment('3_months');
        $this->assertSame('pending', $payment['status']);
        $this->assertSame('60.00', $payment['amount']);

        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'paid'));
        $row = Db::row('SELECT * FROM pay_payments WHERE id=?', [$payment['id']]);
        $this->assertSame('paid', $row['status']);
        $invoice = Db::row('SELECT * FROM billing_invoices WHERE id=?', [$row['invoice_id']]);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame('3_months', $invoice['billing_period']);
        $this->assertSame('card', Db::value('SELECT method FROM billing_payments WHERE invoice_id=?', [$invoice['id']]));
        $after = (string) Db::value('SELECT expiry_date FROM customer_profiles WHERE username="alice"');
        $this->assertGreaterThan($before, $after);
        $this->assertSame($payment['period_end'], $after);

        // The redirect and the webhook arrive together: the payment counts once.
        $this->post(self::PAY . '/pay/webhook', ['data' => ['sdkOrder' => ['id' => 'order-12345']]])->assertOk();
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM billing_payments'));
    }

    public function test_nothing_counts_until_pok_says_so(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakePok(['isCompleted' => false]);
        $payment = $this->startPayment();
        $this->post(self::PAY . '/pay/webhook', ['data' => ['sdkOrder' => ['id' => 'order-12345']], 'isCompleted' => true, 'status' => 'paid'])->assertOk();
        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'waiting'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_payments'));
        $this->assertSame('pending', Db::value('SELECT status FROM pay_payments WHERE id=?', [$payment['id']]));
    }

    public function test_a_wrong_amount_or_currency_is_left_for_a_person(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakePok(['isCompleted' => true, 'originalAmount' => 1, 'originalCurrencyCode' => 'EUR']);
        $payment = $this->startPayment();
        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'mismatch'));
        $this->assertSame('mismatch', Db::value('SELECT status FROM pay_payments WHERE id=?', [$payment['id']]));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_payments'));
    }

    public function test_an_unpaid_generated_invoice_for_the_period_is_replaced(): void
    {
        $this->enablePay();
        $this->contract(12, 'monthly', true);
        $first = (int) Db::value('SELECT first_invoice_id FROM customer_contracts');
        $this->fakePok(['isCompleted' => true, 'originalAmount' => 60, 'originalCurrencyCode' => 'EUR']);
        $payment = $this->startPayment('3_months');
        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'paid'));
        $this->assertSame('void', Db::value('SELECT status FROM billing_invoices WHERE id=?', [$first]));
    }

    public function test_nightly_invoices_follow_the_contract_and_stop_at_its_end(): void
    {
        $this->contract(12, '3_months');
        $this->artisan('radiusone:generate-billing', ['--username' => 'alice'])->assertSuccessful();
        $this->assertSame('3_months', Db::value('SELECT billing_period FROM billing_invoices WHERE username="alice" ORDER BY id DESC LIMIT 1'));

        Db::exec('DELETE FROM billing_invoices');
        Db::exec('UPDATE customer_contracts SET end_date=DATE_ADD(CURDATE(), INTERVAL 10 DAY)');
        $this->artisan('radiusone:generate-billing', ['--username' => 'alice'])->assertSuccessful();
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_invoices'));
    }

    public function test_settings_keep_the_secret_and_check_the_address(): void
    {
        $base = ['enabled' => '0', 'pok_enabled' => '0', 'stripe_enabled' => '0', 'language' => 'sq', 'color' => '#112233', 'title' => 'Pay', 'intro' => '', 'footer' => '', 'pok_mode' => 'test', 'stripe_mode' => 'test', 'pok_test_merchant_id' => 'm', 'pok_test_key_id' => 'k'];
        $this->post('/settings/pay', $base + ['host' => 'https://bad', 'pok_test_key_secret' => ''])->assertSessionHasErrors();
        $this->post('/settings/pay', $base + ['host' => 'pay.example.com', 'pok_test_key_secret' => 'top-secret'])->assertSessionHas('flash.type', 'success');
        $this->post('/settings/pay', ['color' => 'red'] + $base + ['host' => 'pay.example.com', 'pok_test_key_secret' => ''])->assertSessionHasErrors();
        $this->post('/settings/pay', $base + ['host' => 'pay.example.com', 'pok_test_key_secret' => ''])->assertSessionHas('flash.type', 'success');
        $this->assertSame('top-secret', Settings::value('pok_test_key_secret'));
        $this->assertNotSame('top-secret', Db::value('SELECT setting_value FROM settings WHERE setting_key="pok_test_key_secret"'));
        $this->get('/settings?tab=pay')->assertOk()->assertInertia(fn ($page) => $page->where('pay.saved.pok_test', true)->where('pay.saved.pok_live', false)->missing('pay.values.pok_test_key_secret'));
        // Live needs its own credentials before POK can be offered live.
        $this->post('/settings/pay', ['pok_enabled' => '1', 'pok_mode' => 'live'] + $base + ['host' => 'pay.example.com'])->assertSessionHasErrors();
    }

    private function enableStripe(): void
    {
        Settings::save('pay_enabled', '1');
        Settings::save('pay_host', 'pay.radiusone.test');
        Settings::save('stripe_enabled', '1');
        Settings::save('stripe_mode', 'live');
        Settings::save('stripe_live_secret_key', 'sk_live_abc', true);
    }

    private function fakeStripe(array $session): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_test_12345'] + $session),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_12345', 'url' => 'https://checkout.stripe.test/c/pay/cs_test_12345', 'expires_at' => time() + 3600]),
        ]);
    }

    public function test_a_stripe_payment_issues_the_invoice_and_renews(): void
    {
        $this->enableStripe();
        $this->contract(12);
        $this->fakeStripe(['status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 6000, 'currency' => 'eur', 'payment_intent' => 'pi_1']);
        $payment = $this->startPayment('3_months', 'stripe');
        $this->assertSame('stripe', $payment['provider']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions')
            && $request['line_items'][0]['price_data']['unit_amount'] === 6000
            && $request['line_items'][0]['price_data']['currency'] === 'eur'
            && $request['mode'] === 'payment'
            && $request->hasHeader('Idempotency-Key', $payment['token']));

        $this->post(self::PAY . '/pay/webhook', ['type' => 'checkout.session.completed', 'id' => 'evt_123456789', 'data' => ['object' => ['id' => 'cs_test_12345']]])->assertOk();
        $row = Db::row('SELECT * FROM pay_payments WHERE id=?', [$payment['id']]);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('Stripe pi_1', Db::value('SELECT reference FROM billing_payments WHERE invoice_id=?', [$row['invoice_id']]));
    }

    public function test_stripe_counts_only_when_the_session_is_paid_for_the_full_amount(): void
    {
        $this->enableStripe();
        $this->contract(12);
        // Complete, but a slower payment method has not settled.
        $this->fakeStripe(['status' => 'complete', 'payment_status' => 'unpaid', 'amount_total' => 2000, 'currency' => 'eur']);
        $payment = $this->startPayment('monthly', 'stripe');
        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'waiting'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_payments'));

        Http::swap(new Factory);
        $this->fakeStripe(['status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'eur']);
        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'mismatch'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_payments'));
    }

    public function test_an_expired_stripe_session_is_not_a_payment(): void
    {
        $this->enableStripe();
        $this->contract(12);
        $this->fakeStripe(['status' => 'expired', 'payment_status' => 'unpaid', 'amount_total' => 2000, 'currency' => 'eur']);
        $payment = $this->startPayment('monthly', 'stripe');
        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'canceled'));
        $this->assertSame('canceled', Db::value('SELECT status FROM pay_payments WHERE id=?', [$payment['id']]));
    }

    public function test_a_service_that_is_off_cannot_be_chosen(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakeStripe([]);
        $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567']);
        $this->post(self::PAY . '/pay/start', ['period' => 'monthly', 'provider' => 'stripe']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM pay_payments'));
        $this->get(self::PAY . '/pay')->assertInertia(fn ($page) => $page->where('page.providers', [['id' => 'pok', 'name' => 'POK']]));
    }

    public function test_stripe_settings_keep_the_key_secret(): void
    {
        $base = ['enabled' => '0', 'pok_enabled' => '0', 'language' => 'en', 'color' => '#112233', 'title' => '', 'intro' => '', 'footer' => '', 'pok_mode' => 'test', 'stripe_mode' => 'test', 'host' => 'pay.example.com'];
        $this->post('/settings/pay', $base + ['stripe_enabled' => '1', 'stripe_test_secret_key' => 'pk_test_public'])->assertSessionHasErrors();
        $this->post('/settings/pay', $base + ['stripe_enabled' => '1', 'stripe_test_secret_key' => ''])->assertSessionHasErrors();
        // A live key in the test field, or the other way round, is refused.
        $this->post('/settings/pay', $base + ['stripe_enabled' => '1', 'stripe_test_secret_key' => 'sk_live_abc123'])->assertSessionHasErrors();
        $this->post('/settings/pay', $base + ['stripe_live_secret_key' => 'sk_test_abc123'])->assertSessionHasErrors();
        $this->post('/settings/pay', $base + ['stripe_enabled' => '1', 'stripe_test_secret_key' => 'sk_test_abc123'])->assertSessionHas('flash.type', 'success');
        $this->assertNotSame('sk_test_abc123', Db::value('SELECT setting_value FROM settings WHERE setting_key="stripe_test_secret_key"'));
        $response = $this->get('/settings?tab=pay')->assertOk();
        $response->assertInertia(fn ($page) => $page->where('pay.saved.stripe_test', true)->where('pay.saved.stripe_live', false)->where('pay.values.stripe_mode', 'test'));
        $this->assertStringNotContainsString('sk_test_abc123', $response->getContent());
    }

    public function test_the_phone_digits_must_match_and_guessing_them_is_stopped(): void
    {
        $this->enablePay();
        $this->contract(12);
        $wrong = $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '1111'])->getSession()->get('flash.message');
        $unknown = $this->post(self::PAY . '/pay/lookup', ['username' => 'nobody', 'phone' => '4567'])->getSession()->get('flash.message');
        $this->assertSame($wrong, $unknown);
        $this->get(self::PAY . '/pay')->assertInertia(fn ($page) => $page->where('account', null));
        $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567']);
        $this->get(self::PAY . '/pay')->assertInertia(fn ($page) => $page->where('account.username', 'alice'));
        // Five wrong numbers: the username waits, even with the right digits.
        foreach (['0000', '0001', '0002', '0003', '0004'] as $digits) {
            $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => $digits]);
        }
        $locked = $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567'])->getSession()->get('flash.message');
        $this->assertSame(PayPage::LABELS['sq']['locked'], $locked);
        $this->get(self::PAY . '/pay')->assertInertia(fn ($page) => $page->where('account', null));
    }

    public function test_online_payment_can_be_turned_off_for_one_client(): void
    {
        $this->enablePay();
        $this->contract(12);
        Db::exec('UPDATE customer_profiles SET online_pay=0 WHERE username="alice"');
        $message = $this->post(self::PAY . '/pay/lookup', ['username' => 'alice', 'phone' => '4567'])->getSession()->get('flash.message');
        $this->assertSame(PayPage::LABELS['sq']['no_online'], $message);
        $this->assertNull(PayPage::account('alice'));
        $this->get(self::PORTAL . '/clients/alice/edit')->assertInertia(fn ($page) => $page->where('values.online_pay', false)->where('payPage', true));
    }

    public function test_a_test_payment_is_recorded_but_pays_nothing(): void
    {
        $this->enablePay();
        Settings::save('pok_mode', 'test');
        Settings::save('pok_test_merchant_id', 'merchant-test');
        Settings::save('pok_test_key_id', 'key-test');
        Settings::save('pok_test_key_secret', 'secret-test', true);
        $this->contract(12);
        $before = (string) Db::value('SELECT expiry_date FROM customer_profiles WHERE username="alice"');
        $this->get(self::PAY . '/pay')->assertInertia(fn ($page) => $page->where('page.testing', true));
        $this->fakePok(['isCompleted' => true, 'originalAmount' => 20, 'originalCurrencyCode' => 'EUR', 'transactionId' => 'tx-test']);
        $payment = $this->startPayment('monthly');
        $this->assertSame('test', $payment['mode']);
        // Test payments go to POK's staging service with the test keys.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api-staging.pokpay.io/merchants/merchant-test/'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://api.pokpay.io/'));

        $this->get(self::PAY . '/pay/return/' . $payment['token'])->assertInertia(fn ($page) => $page->where('state.status', 'paid')->where('state.test', true));
        $row = Db::row('SELECT * FROM pay_payments WHERE id=?', [$payment['id']]);
        $this->assertSame('paid', $row['status']);
        $this->assertNull($row['invoice_id']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM billing_payments'));
        $this->assertSame($before, (string) Db::value('SELECT expiry_date FROM customer_profiles WHERE username="alice"'));

        // Switching to live later: the open test payment is still checked with the test keys.
        Settings::save('pok_mode', 'live');
        $this->get(self::PORTAL . '/payments')->assertInertia(fn ($page) => $page->where('result.rows.0.provider', 'POK (test)')->where('metrics.0.value', '0.00 EUR'));
    }

    public function test_live_payments_use_pok_production(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakePok([]);
        $payment = $this->startPayment('monthly');
        $this->assertSame('live', $payment['mode']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.pokpay.io/merchants/merchant-1/'));
        $this->get(self::PAY . '/pay')->assertInertia(fn ($page) => $page->where('page.testing', false));
    }

    public function test_operators_can_follow_payments(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakePok(['isCompleted' => true, 'originalAmount' => 20, 'originalCurrencyCode' => 'EUR', 'transactionId' => 'tx-9']);
        $payment = $this->startPayment();
        $this->post(self::PAY . '/pay/webhook', ['id' => 'order-12345'])->assertOk();

        $this->get(self::PORTAL . '/payments')->assertOk()->assertInertia(fn ($page) => $page->component('payments/Index')
            ->where('result.total', 1)
            ->where('result.rows.0.status', 'paid')
            ->where('result.rows.0.customer', 'Alice Doe')
            ->where('metrics.0.value', '20.00 EUR')
            ->where('counts.paid', 1));
        $this->get(self::PORTAL . '/payments?status=pending')->assertInertia(fn ($page) => $page->where('result.total', 0));
        $this->get(self::PORTAL . '/payments?provider=stripe')->assertInertia(fn ($page) => $page->where('result.total', 0));
        $this->get(self::PORTAL . '/payments?q=alice')->assertInertia(fn ($page) => $page->where('result.total', 1));
        $this->get(self::PORTAL . '/payments?q=' . rawurlencode("x' OR 1=1 -- "))->assertInertia(fn ($page) => $page->where('result.total', 0));
        $this->get(self::PORTAL . '/payments/' . $payment['id'])->assertOk()->assertInertia(fn ($page) => $page->component('payments/Show')->where('payment.order', 'order-12345'));
        $csv = $this->get(self::PORTAL . '/payments/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('order-12345', $csv);
        $this->assertStringContainsString('alice', $csv);
    }

    public function test_check_now_asks_the_service_and_a_review_closes_a_problem(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakePok(['isCompleted' => false]);
        $payment = $this->startPayment();
        $this->post(self::PORTAL . '/payments/' . $payment['id'] . '/check')->assertSessionHas('flash.type', 'warning');
        $this->assertSame('pending', Db::value('SELECT status FROM pay_payments WHERE id=?', [$payment['id']]));

        Http::swap(new Factory);
        $this->fakePok(['isCompleted' => true, 'originalAmount' => 1, 'originalCurrencyCode' => 'EUR']);
        $this->post(self::PORTAL . '/payments/' . $payment['id'] . '/check')->assertSessionHas('flash.type', 'warning');
        $this->assertSame('mismatch', Db::value('SELECT status FROM pay_payments WHERE id=?', [$payment['id']]));
        $this->assertArrayHasKey('pay:' . $payment['id'], AdminAlerts::problems(['pay']));

        $this->post(self::PORTAL . '/payments/' . $payment['id'] . '/review', ['note' => ''])->assertSessionHas('flash.type', 'danger');
        $this->post(self::PORTAL . '/payments/' . $payment['id'] . '/review', ['note' => 'Refunded at POK.'])->assertSessionHas('flash.type', 'success');
        $row = Db::row('SELECT * FROM pay_payments WHERE id=?', [$payment['id']]);
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame('Refunded at POK.', $row['review_note']);
        $this->assertSame([], AdminAlerts::problems(['pay']));
    }

    public function test_a_read_only_operator_can_look_but_not_act(): void
    {
        $this->enablePay();
        $this->contract(12);
        $this->fakePok(['isCompleted' => false]);
        $payment = $this->startPayment();
        $this->actingAsOperator('view');
        $this->get(self::PORTAL . '/payments')->assertOk();
        $this->post(self::PORTAL . '/payments/' . $payment['id'] . '/check')->assertForbidden();
        $this->post(self::PORTAL . '/payments/' . $payment['id'] . '/review', ['note' => 'x'])->assertForbidden();
    }

    public function test_the_colour_picks_readable_text(): void
    {
        $this->assertSame('#ffffff', PayPage::readableOn('#0d7f78'));
        $this->assertSame('#000000', PayPage::readableOn('#ffe600'));
    }
}
