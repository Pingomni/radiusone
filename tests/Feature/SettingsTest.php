<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Db;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    public function test_profile_page_and_details_update(): void
    {
        $user = $this->actingAsOperator('view');
        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page
            ->component('profile/Profile')
            ->where('profile.username', $user['username'])
            ->where('twoFactor.state', 'off'));

        $this->post('/profile/save', ['name' => 'New Name', 'email' => 'bad'])->assertSessionHas('flash.type', 'danger');
        $this->post('/profile/save', ['name' => 'New Name', 'email' => 'new@example.test'])->assertRedirect('/profile');
        $this->assertSame('New Name', Db::value('SELECT name FROM portal_users WHERE id=?', [$user['id']]));
    }

    public function test_password_change_checks_current_password_and_signs_out(): void
    {
        $user = $this->actingAsOperator('view', ['password' => 'old-password-1']);
        $this->post('/profile/password', ['current_password' => 'wrong', 'new_password' => 'new-password-1', 'new_password_confirm' => 'new-password-1'])
            ->assertSessionHas('flash.message', 'Current password is incorrect.');
        $this->post('/profile/password', ['current_password' => 'old-password-1', 'new_password' => 'new-password-1', 'new_password_confirm' => 'new-password-1'])
            ->assertRedirect('/profile');
        $this->assertTrue(password_verify('new-password-1', (string) Db::value('SELECT password_hash FROM portal_users WHERE id=?', [$user['id']])));
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_profile_two_factor_enrolment_keeps_the_session(): void
    {
        $user = $this->actingAsOperator('view');
        $this->post('/profile/2fa/start')->assertRedirect('/profile/2fa/setup');
        $secret = session('profile_2fa_secret');
        $this->get('/profile/2fa/setup')->assertOk()->assertInertia(fn ($page) => $page->component('profile/TwoFactorSetup')->where('secret', $secret));

        $this->post('/profile/2fa/enable', ['code' => '000000'])->assertSessionHas('flash.message', 'Authenticator code is invalid.');
        $this->post('/profile/2fa/enable', ['code' => $this->totp($secret)])->assertRedirect('/profile');
        $this->assertSame(1, (int) Db::value('SELECT two_factor_enabled FROM portal_users WHERE id=?', [$user['id']]));
        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->where('twoFactor.state', 'enabled'));

        $this->post('/profile/2fa/disable')->assertRedirect('/profile');
        $this->assertSame(0, (int) Db::value('SELECT two_factor_enabled FROM portal_users WHERE id=?', [$user['id']]));
    }

    public function test_settings_tabs_render(): void
    {
        $this->actingAsOperator();
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page->component('settings/Settings')->where('tab', 'overview')->has('overview.rows', 8)
            ->where('overview.rows.0', ['Version', 'RadiusWeb ' . trim((string) file_get_contents(base_path('VERSION')))])
            ->where('appVersion', trim((string) file_get_contents(base_path('VERSION')))));
        $this->get('/settings?tab=users')->assertOk()->assertInertia(fn ($page) => $page->where('tab', 'users')->has('users', 1));
        $this->get('/settings?tab=notifications')->assertOk()->assertInertia(fn ($page) => $page->where('notifications.view', 'gateways'));
        $this->get('/settings?tab=notifications&view=gateways&gateway=email')->assertOk()->assertInertia(fn ($page) => $page
            ->where('notifications.gateway', 'email')
            ->missing('notifications.email.password'));
        $this->get('/settings?tab=notifications&view=templates&template=welcome')->assertOk()->assertInertia(fn ($page) => $page->where('notifications.template', 'welcome'));
        $this->get('/settings?tab=notifications&view=history')->assertOk()->assertInertia(fn ($page) => $page->has('notifications.history.rows', 0));
        $this->get('/settings?tab=custom')->assertOk()->assertInertia(fn ($page) => $page->where('tab', 'branding'));
        $this->get('/settings/notifications/sms')->assertRedirect('/settings?tab=notifications&view=gateways&gateway=sms');
    }

    public function test_operator_create_edit_and_delete(): void
    {
        $this->actingAsOperator();
        $this->get('/settings/users/create')->assertOk()->assertInertia(fn ($page) => $page->component('settings/UserForm')->where('user', null));

        $this->post('/settings/users/save', ['name' => 'Ana', 'email' => 'ana@example.test', 'username' => 'ana', 'role' => 'view', 'password' => 'short', 'enabled' => true])
            ->assertRedirect('/settings/users/create')->assertSessionHasErrors('password');
        $this->post('/settings/users/save', ['name' => 'Ana', 'email' => 'ana@example.test', 'username' => 'ana', 'role' => 'view', 'password' => 'long-enough-pass', 'enabled' => true])
            ->assertRedirect('/settings?tab=users');
        $ana = Db::row('SELECT * FROM portal_users WHERE username="ana"');
        $this->assertSame(1, (int) $ana['enabled']);
        $this->assertStringNotContainsString('long-enough-pass', (string) Db::value('SELECT GROUP_CONCAT(COALESCE(after_json,"")) FROM audit_logs'));

        // Duplicate username is a field error, not a 500.
        $this->post('/settings/users/save', ['name' => 'Dup', 'email' => 'dup@example.test', 'username' => 'ana', 'role' => 'view', 'password' => 'long-enough-pass'])
            ->assertRedirect('/settings/users/create')->assertSessionHasErrors('username');

        // Unchecked "enabled" is sent as false by the React form.
        $this->post('/settings/users/save', ['id' => $ana['id'], 'name' => 'Ana B', 'email' => 'ana@example.test', 'username' => 'ana', 'role' => 'view', 'password' => '', 'enabled' => false, 'two_factor_action' => 'generate'])
            ->assertRedirect('/settings?tab=users');
        $ana = Db::row('SELECT * FROM portal_users WHERE username="ana"');
        $this->assertSame(0, (int) $ana['enabled']);
        $this->assertNotEmpty($ana['two_factor_secret']);

        $this->post('/settings/users/' . $ana['id'] . '/delete')->assertRedirect('/settings?tab=users');
        $this->assertNull(Db::row('SELECT id FROM portal_users WHERE username="ana"'));
    }

    public function test_operators_cannot_change_their_own_role_or_delete_themselves(): void
    {
        $me = $this->actingAsOperator();
        $this->post('/settings/users/save', ['id' => $me['id'], 'name' => 'x', 'email' => 'x@example.test', 'username' => $me['username'], 'role' => 'view'])
            ->assertSessionHas('flash.message', 'You cannot change your own role.');
        $this->post('/settings/users/' . $me['id'] . '/delete')->assertSessionHas('flash.message', 'You cannot delete your own user.');
    }

    public function test_branding_upload_url_and_reset(): void
    {
        $this->actingAsOperator();
        $dir = getenv('FRWEB_TEST_DIR') . '/branding';
        putenv('BRANDING_STORAGE=' . $dir);
        $_ENV['BRANDING_STORAGE'] = $_SERVER['BRANDING_STORAGE'] = $dir;

        // 1x1 PNG (GD is not installed on the server, so no image factory).
        $png = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->post('/settings/branding/logo', ['portal_logo' => $png])->assertRedirect('/settings?tab=branding')->assertSessionHas('flash.type', 'success');
        $path = Settings::value('portal_logo_path');
        $this->assertMatchesRegularExpression('#^/uploads/branding/logo-[0-9a-f]{16}\.png$#', $path);
        $this->assertFileExists($dir . '/' . basename($path));
        $this->get($path)->assertOk();

        $this->post('/settings/branding/logo/url', ['portal_logo_url' => 'http://insecure.example/logo.png'])->assertSessionHas('flash.type', 'danger');
        $this->post('/settings/branding/logo/url', ['portal_logo_url' => 'https://example.com/logo.png'])->assertSessionHas('flash.type', 'success');
        $this->assertFileDoesNotExist($dir . '/' . basename($path));
        $this->assertSame('https://example.com/logo.png', Settings::value('portal_logo_path'));

        $this->post('/settings/branding/logo/delete')->assertRedirect('/settings?tab=branding');
        $this->assertSame('', Settings::value('portal_logo_path'));
    }

    public function test_gateway_checkbox_off_is_saved_as_disabled(): void
    {
        $this->actingAsOperator();
        Settings::save('sms_gateway_enabled', '1');
        $this->post('/settings/notifications/sms-gateway', ['sms_gateway_enabled' => false, 'sms_gateway_method' => 'GET', 'sms_gateway_url' => '', 'sms_gateway_body' => '', 'sms_gateway_success' => ''])
            ->assertRedirect('/settings?tab=notifications&view=gateways');
        $this->assertSame('0', Settings::value('sms_gateway_enabled'));
    }

    public function test_view_role_cannot_open_settings(): void
    {
        $this->actingAsOperator('view');
        $this->get('/settings')->assertForbidden();
        $this->get('/settings/users/create')->assertForbidden();
    }
}
