<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ClientsTest extends TestCase
{
    private function plan(): void
    {
        $this->post('/services/save', [
            'id' => '', 'name' => 'Home 50', 'group_name' => 'home50', 'description' => '', 'enabled' => true, 'download_speed' => '50', 'upload_speed' => '10',
            'ip_mode' => 'pool', 'ip_pool' => '', 'session_timeout' => '', 'idle_timeout' => '', 'simultaneous_use' => '1', 'expiration_behavior' => '',
            'billing_mode' => 'prepaid', 'expired_service_group' => '', 'price' => '20.00', 'currency' => 'EUR', 'custom_group_attrs' => '',
        ])->assertSessionHas('flash.type', 'success');
    }

    private function client(array $overrides = []): array
    {
        return array_merge([
            'old_username' => '', 'auth_method' => 'pppoe', 'username' => 'alice', 'password' => 'pppoe-pass-1', 'verified' => '0',
            'account_status' => 'enabled', 'expiry_date' => date('Y-m-d', strtotime('+30 days')), 'service_group' => 'home50',
            'framed_ip_address' => '', 'framed_pool' => '', 'email_alert' => true, 'sms_alert' => false, 'access_profile_id' => '',
            'dhcp_client_id' => '', 'circuit_id' => '', 'mac_address' => '', 'mac_lock' => false, 'nas_restriction_type' => 'all',
            'nas_restriction_value' => '', 'language' => 'en', 'first_name' => 'Alice', 'last_name' => 'Doe', 'company_name' => '',
            'vat_tax_id' => '', 'phone' => '+355600000', 'cell_phone' => '', 'email' => 'alice@example.test', 'address' => '', 'city' => 'Tirana',
            'state' => '', 'zip' => '', 'country' => 'AL', 'gps' => '', 'contract_id' => '', 'contract_valid_until' => '', 'notes' => '',
            '_active_tab' => 'account',
        ], $overrides);
    }

    public function test_create_view_and_list_a_client(): void
    {
        $this->actingAsOperator();
        $this->plan();
        $this->get('/clients/create')->assertOk()->assertInertia(fn ($page) => $page->component('clients/Form')->where('client', null)->where('canEdit', true));

        $this->post('/clients/save', $this->client())->assertRedirect('/clients/alice/edit?tab=account')->assertSessionHas('flash.type', 'success');
        $profile = Db::row('SELECT * FROM customer_profiles WHERE username="alice"');
        $this->assertSame('active', $profile['account_status']);
        $this->assertSame(1, (int) $profile['email_alert']);
        $this->assertSame(0, (int) $profile['sms_alert']);
        $this->assertStringNotContainsString('pppoe-pass-1', (string) Db::value('SELECT GROUP_CONCAT(COALESCE(after_json,"")) FROM audit_logs'));

        $this->get('/clients/alice/edit')->assertOk()->assertInertia(fn ($page) => $page
            ->where('client.username', 'alice')
            ->where('client.header.name', 'Alice Doe')
            ->where('client.header.status.text', 'enabled')
            ->where('values.email_alert', true)
            ->where('values.password', '')
            ->has('client.overview.items')
            ->where('client.billing.invoices', []));
        $this->getJson('/clients/alice/overview')->assertOk()->assertJsonPath('items.0.value', 'alice');

        $this->get('/clients?q=ali')->assertOk()->assertInertia(fn ($page) => $page
            ->component('clients/Index')
            ->where('result.total', 1)
            ->where('result.rows.0.customer', 'Alice Doe'));
        $this->get('/clients?status=archived')->assertOk()->assertInertia(fn ($page) => $page->where('result.total', 0));

        $csv = $this->get('/clients/export?q=ali')->assertOk()->streamedContent();
        $this->assertStringContainsString('alice,"Alice Doe"', $csv);

        $this->get('/clients/nobody/edit')->assertNotFound();
        $this->get('/clients/nobody/diagnostics')->assertNotFound();
    }

    public function test_validation_errors_stay_on_the_form(): void
    {
        $this->actingAsOperator();
        $this->plan();
        $this->post('/clients/save', $this->client(['service_group' => 'missing']))
            ->assertRedirect('/clients/create?tab=account')
            ->assertSessionHasErrors('service_group');
        $this->post('/clients/save', $this->client(['auth_method' => 'dhcp', 'mac_address' => 'zz']))
            ->assertSessionHasErrors('mac_address');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM customer_profiles'));
    }

    public function test_rename_needs_no_new_password_prompt(): void
    {
        $user = $this->actingAsOperator();
        $this->plan();
        $this->post('/clients/save', $this->client());

        // Signed in an hour ago: the rename saves straight away.
        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time() - 3600]);
        $this->post('/clients/save', $this->client(['old_username' => 'alice', 'username' => 'alice2', 'password' => '', 'city' => 'Durres', '_active_tab' => 'personal']))
            ->assertSessionHas('flash.type', 'success');
        $this->assertNull(Db::row('SELECT id FROM customer_profiles WHERE username="alice"'));
        $this->assertSame('Durres', Db::value('SELECT city FROM customer_profiles WHERE username="alice2"'));
    }

    public function test_reveal_password_bulk_suspend_and_archive(): void
    {
        $this->actingAsOperator();
        $this->plan();
        $this->post('/clients/save', $this->client());

        $this->post('/clients/alice/reveal-password')->assertRedirect('/clients/alice/edit');
        $this->get('/clients/alice/edit')->assertInertia(fn ($page) => $page->where('client.revealed', 'pppoe-pass-1'));
        $this->get('/clients/alice/edit')->assertInertia(fn ($page) => $page->where('client.revealed', null));

        $this->post('/clients/bulk', ['bulk_action' => 'suspend', 'clients' => ['alice']])->assertRedirect('/clients');
        $this->assertSame('suspended', Db::value('SELECT account_status FROM customer_profiles WHERE username="alice"'));
        $this->post('/clients/bulk', ['bulk_action' => 'disconnect', 'clients' => ['alice']])->assertSessionHas('flash.message', 'Choose a valid bulk action.');

        $this->post('/clients/alice/delete')->assertRedirect('/clients');
        $this->assertSame('archived', Db::value('SELECT account_status FROM customer_profiles WHERE username="alice"'));
        $this->get('/clients/alice/edit')->assertInertia(fn ($page) => $page->where('canEdit', false)->where('client.overview.revealPassword', false));
    }

    public function test_diagnostics_and_suspension(): void
    {
        $this->actingAsOperator();
        $this->plan();
        $this->post('/clients/save', $this->client());

        $this->get('/clients/alice/diagnostics')->assertOk()->assertInertia(fn ($page) => $page
            ->component('clients/Diagnostics')
            ->where('signals.0.signal', 'Billing state')
            ->where('sources', ['manual' => 'Manual', 'security' => 'Security']));
        $this->post('/clients/alice/suspension', ['action' => 'suspend', 'source' => 'manual'])->assertRedirect('/clients/alice/diagnostics');
        $this->assertSame('suspended', Db::value('SELECT account_status FROM customer_profiles WHERE username="alice"'));
        $this->post('/clients/alice/suspension', ['action' => 'restore', 'source' => 'manual']);
        $this->assertSame('active', Db::value('SELECT account_status FROM customer_profiles WHERE username="alice"'));
    }

    public function test_documents_upload_and_download(): void
    {
        $dir = getenv('FRWEB_TEST_DIR') . '/documents';
        @mkdir($dir, 0750, true);
        putenv('DOCUMENT_STORAGE=' . $dir);
        $_ENV['DOCUMENT_STORAGE'] = $_SERVER['DOCUMENT_STORAGE'] = $dir;

        $this->actingAsOperator();
        $this->plan();
        $pdf = UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->post('/clients/save', $this->client(['id_front' => $pdf]))->assertSessionHas('flash.type', 'success');
        $doc = Db::row('SELECT * FROM uploaded_documents WHERE username="alice"');
        $this->assertSame('id_front', $doc['document_type']);

        $this->get('/clients/alice/edit')->assertInertia(fn ($page) => $page->where('client.documents.0.name', 'id.pdf'));
        $this->get('/documents/' . $doc['id'] . '/download')->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->post('/documents/' . $doc['id'] . '/delete')->assertSessionHas('flash.type', 'danger');
    }

    public function test_usage_history_per_day_and_month(): void
    {
        $this->actingAsOperator();
        $this->plan();
        $this->post('/clients/save', $this->client());
        $today = (string) Db::value('SELECT CURDATE()');
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        // Two sessions reported today, one yesterday, one long ago (outside 30 days), one for another client.
        foreach ([['alice', $today . ' 08:00:00', 1000, 100], ['alice', $today . ' 09:00:00', 500, 50], ['alice', $yesterday . ' 23:00:00', 300, 30],
            ['alice', date('Y-m-d', strtotime($today . ' -60 days')) . ' 12:00:00', 9000, 900], ['bob', $today . ' 10:00:00', 7000, 700]] as [$user, $when, $down, $up]) {
            $this->insertRow('radacct', ['username' => $user, 'acctstarttime' => $when, 'acctupdatetime' => $when, 'acctoutputoctets' => $down, 'acctinputoctets' => $up]);
        }

        $this->get('/clients/alice/edit')->assertOk()->assertInertia(fn ($page) => $page
            ->where('client.usage.range', '30d')
            ->has('client.usage.points', 30)
            ->where('client.usage.total', ['down' => 1800, 'up' => 180]));
        $week = $this->getJson('/clients/alice/usage?range=7d')->assertOk()->assertJsonCount(7, 'points')->json();
        $this->assertSame(['key' => $today, 'down' => 1500, 'up' => 150], $week['points'][6]);
        $this->assertSame(['key' => $yesterday, 'down' => 300, 'up' => 30], $week['points'][5]);
        $year = $this->getJson('/clients/alice/usage?range=12m')->assertOk()->assertJsonCount(12, 'points')->assertJsonPath('monthly', true)->json();
        $this->assertSame(substr($today, 0, 7), $year['points'][11]['key']);
        $this->assertSame(['down' => 10800, 'up' => 1080], $year['total']);

        $this->getJson('/clients/alice/usage?range=5y')->assertNotFound();
        $this->getJson('/clients/nobody/usage')->assertNotFound();
    }

    public function test_view_role_is_read_only(): void
    {
        $this->actingAsOperator();
        $this->plan();
        $this->post('/clients/save', $this->client());
        $this->actingAsOperator('view');
        $this->get('/clients/alice/edit')->assertOk()->assertInertia(fn ($page) => $page->where('canEdit', false)->where('client.overview.revealPassword', false));
        $this->post('/clients/save', $this->client(['old_username' => 'alice']))->assertForbidden();
        $this->post('/clients/alice/delete')->assertForbidden();
    }
}
