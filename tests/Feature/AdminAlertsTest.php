<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminAlerts;
use App\Support\Db;
use App\Support\ProviderHttp;
use Tests\TestCase;

final class AdminAlertsTest extends TestCase
{
    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1';

    /** @var list<array{url: string, chat: string, text: string}> */
    private array $sent = [];

    /** Answers every Telegram call with $status; records what was posted. */
    private function telegram(int $status = 200, string $response = '{"ok":true}'): void
    {
        $this->sent = [];
        ProviderHttp::fake(function (string $method, string $url, array $headers, $body) use ($status, $response): array {
            $json = json_decode((string) $body, true) ?: [];
            $this->sent[] = ['url' => $url, 'chat' => (string) ($json['chat_id'] ?? ''), 'text' => (string) ($json['text'] ?? '')];
            return [$status, $response];
        });
    }

    protected function tearDown(): void
    {
        ProviderHttp::fake(null);
        parent::tearDown();
    }

    /** Alerts on, Telegram only, watching background jobs (the one check a test can trigger). */
    private function enable(array $overrides = []): void
    {
        AdminAlerts::save($overrides + [
            'enabled' => true, 'alert_telegram' => true, 'telegram_token' => self::TOKEN, 'telegram_chats' => '111, -1002',
            'checks' => ['jobs'], 'report_time' => '07:00',
        ]);
    }

    /** A router change queued 20 minutes ago: the network worker reads as stuck. */
    private function stuckWorker(): void
    {
        Db::exec('SET FOREIGN_KEY_CHECKS=0');
        $id = $this->insertRow('network_operations', ['subscription_id' => 3, 'policy_revision' => 1, 'action' => 'apply', 'dedupe_key' => 'k1', 'state' => 'pending']);
        Db::exec('UPDATE network_operations SET created_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE id=?', [$id]);
        Db::exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_settings_are_validated_and_the_bot_token_never_reaches_the_page(): void
    {
        $this->actingAsOperator();
        $back = '/settings?tab=notifications&view=admin';
        $base = ['enabled' => true, 'emails' => 'noc@example.test', 'report_time' => '07:00'];
        foreach ([
            ['emails' => 'not-an-email'],
            ['phones' => 'abc'],
            ['telegram_chats' => 'no spaces allowed'],
            ['telegram_token' => 'nope'],
            ['alert_telegram' => true, 'telegram_chats' => '111'],
            ['report_time' => '25:00'],
            ['emails' => ''],
        ] as $bad) {
            $this->post('/settings/notifications/admin', $bad + $base)->assertRedirect($back)->assertSessionHas('flash.type', 'danger');
        }
        $this->assertFalse(AdminAlerts::settings()['enabled']);

        $this->post('/settings/notifications/admin', $base + ['alert_telegram' => true, 'telegram_token' => self::TOKEN, 'telegram_chats' => "111\n@noc_channel", 'checks' => ['jobs', 'bogus']])
            ->assertSessionHas('flash.type', 'success');
        $settings = AdminAlerts::settings();
        $this->assertTrue($settings['enabled']);
        $this->assertSame('111, @noc_channel', $settings['telegram_chats']);
        $this->assertSame(['jobs'], $settings['checks']);
        $this->assertSame(self::TOKEN, $settings['telegram_token']);
        $this->assertStringNotContainsString(self::TOKEN, (string) Db::value('SELECT setting_value FROM settings WHERE setting_key="notification_admin_telegram_token"'));

        $this->get($back)->assertOk()->assertInertia(fn ($page) => $page
            ->where('notifications.admin.settings.telegram_token_saved', true)
            ->missing('notifications.admin.settings.telegram_token'));
        // A blank token keeps the saved one.
        $this->post('/settings/notifications/admin', $base + ['alert_telegram' => true, 'telegram_token' => '', 'telegram_chats' => '111'])->assertSessionHas('flash.type', 'success');
        $this->assertSame(self::TOKEN, AdminAlerts::settings()['telegram_token']);
    }

    public function test_view_role_cannot_change_admin_alerts(): void
    {
        $this->actingAsOperator('view');
        $this->post('/settings/notifications/admin', ['enabled' => true, 'emails' => 'noc@example.test'])->assertForbidden();
        $this->post('/settings/notifications/admin/test')->assertForbidden();
        $this->assertFalse(AdminAlerts::settings()['enabled']);
    }

    public function test_nothing_is_checked_or_sent_while_switched_off(): void
    {
        $this->stuckWorker();
        $this->telegram();
        $this->assertSame(['enabled' => false], AdminAlerts::run());
        $this->artisan('radiusone:admin-alerts')->expectsOutputToContain('"enabled":false')->assertExitCode(0);
        $this->assertSame([], $this->sent);
    }

    public function test_a_problem_alerts_once_after_two_checks_and_then_resolves(): void
    {
        $this->enable();
        $this->stuckWorker();
        $this->telegram();
        $now = time();

        // First sighting may be a restart: nothing is sent yet.
        $this->assertSame(0, AdminAlerts::run($now)['opened']);
        $this->assertSame([], $this->sent);

        // Second sighting in a row: one alert to every chat.
        $this->assertSame(1, AdminAlerts::run($now + 60)['opened']);
        $this->assertSame(['111', '-1002'], array_column($this->sent, 'chat'));
        $this->assertStringStartsWith('https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', $this->sent[0]['url']);
        $this->assertStringContainsString('PROBLEM: Network worker', $this->sent[0]['text']);

        // Still open: no repeat.
        $this->sent = [];
        $result = AdminAlerts::run($now + 120);
        $this->assertSame([0, 1], [$result['opened'], $result['problems']]);
        $this->assertSame([], $this->sent);

        // Cleared: one "resolved" message, with how long it lasted.
        Db::exec('UPDATE network_operations SET state="verified"');
        $result = AdminAlerts::run($now + 300);
        $this->assertSame([1, 0], [$result['resolved'], $result['problems']]);
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('RESOLVED: Network worker', $this->sent[0]['text']);
        $this->assertStringContainsString('All clear', $this->sent[0]['text']);

        $this->assertSame(['resolved', 'problem'], array_column(AdminAlerts::recent(), 'kind'));
        $this->assertStringContainsString('sent to Telegram 111, Telegram -1002', AdminAlerts::recent()[1]['sent']);
    }

    public function test_a_problem_gone_by_the_second_check_never_alerts(): void
    {
        $this->enable();
        $this->stuckWorker();
        $this->telegram();
        AdminAlerts::run(time());
        Db::exec('UPDATE network_operations SET state="verified"');
        $this->assertSame(['problems' => 0, 'opened' => 0, 'resolved' => 0, 'report' => false], AdminAlerts::run(time() + 60));
        $this->assertSame([], $this->sent);
    }

    public function test_unchecked_categories_are_ignored(): void
    {
        $this->enable(['checks' => ['nas']]);
        $this->stuckWorker();
        $this->telegram();
        AdminAlerts::run(time());
        $this->assertSame(0, AdminAlerts::run(time() + 60)['opened']);
        $this->assertSame([], $this->sent);
    }

    public function test_one_failing_chat_does_not_stop_the_others(): void
    {
        $this->enable();
        $this->stuckWorker();
        $calls = 0;
        ProviderHttp::fake(function () use (&$calls): array {
            return ++$calls === 1 ? [400, '{"ok":false,"description":"Bad Request: chat not found"}'] : [200, '{"ok":true}'];
        });
        AdminAlerts::run(time());
        AdminAlerts::run(time() + 60);
        $this->assertSame(2, $calls);
        $sent = AdminAlerts::recent()[0]['sent'];
        $this->assertStringContainsString('sent to Telegram -1002', $sent);
        $this->assertStringContainsString('failed: Telegram 111 (Telegram did not accept the message (HTTP 400): Bad Request: chat not found)', $sent);
    }

    public function test_daily_report_is_sent_once_a_day_after_its_time(): void
    {
        $this->enable(['report_enabled' => true, 'report_telegram' => true, 'report_time' => '07:00']);
        $this->telegram();
        $day = mktime(0, 0, 0, 3, 10, 2026);

        $this->assertFalse(AdminAlerts::run($day + 6 * 3600 + 59 * 60)['report']);
        $this->assertTrue(AdminAlerts::run($day + 7 * 3600)['report']);
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('Daily report 2026-03-10', $this->sent[0]['text']);
        $this->assertStringContainsString('BACKGROUND JOBS', $this->sent[0]['text']);

        $this->assertFalse(AdminAlerts::run($day + 20 * 3600)['report']);
        $this->assertTrue(AdminAlerts::run($day + 86400 + 7 * 3600)['report']);
        $this->assertSame(['report', 'report'], array_column(AdminAlerts::recent(), 'kind'));
    }

    public function test_test_alert_reports_the_outcome_to_the_operator(): void
    {
        $this->actingAsOperator();
        $back = '/settings?tab=notifications&view=admin';

        // No recipients on an enabled channel.
        $this->post('/settings/notifications/admin/test')->assertRedirect($back)->assertSessionHas('flash.type', 'danger');

        $this->enable();
        $this->telegram();
        $this->post('/settings/notifications/admin/test')->assertSessionHas('flash.type', 'success');
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('Test alert', $this->sent[0]['text']);

        // Every recipient refused: the reason is shown.
        $this->telegram(401, '{"ok":false,"description":"Unauthorized"}');
        $this->post('/settings/notifications/admin/test')->assertSessionHas('flash.type', 'danger')
            ->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'Unauthorized'));

        // Some delivered, some not: a warning.
        $calls = 0;
        ProviderHttp::fake(function () use (&$calls): array {
            return ++$calls === 1 ? [200, '{"ok":true}'] : [400, '{"ok":false,"description":"chat not found"}'];
        });
        $this->post('/settings/notifications/admin/test')->assertSessionHas('flash.type', 'warning');
    }
}
