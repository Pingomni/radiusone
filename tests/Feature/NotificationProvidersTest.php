<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\NotificationService;
use App\Support\ProviderHttp;
use App\Support\SmsProviders;
use Tests\TestCase;

final class NotificationProvidersTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array, body: mixed}> */
    private array $sent = [];

    /** Answers provider calls in order; records what was sent. */
    private function provider(array ...$responses): void
    {
        $this->sent = [];
        ProviderHttp::fake(function (string $method, string $url, array $headers, $body) use (&$responses): array {
            $this->sent[] = compact('method', 'url', 'headers', 'body');
            return array_shift($responses) ?? [500, ''];
        });
    }

    protected function tearDown(): void
    {
        ProviderHttp::fake(null);
        parent::tearDown();
    }

    private function email(array $values): void
    {
        $this->post('/settings/notifications/email-gateway', $values + [
            'email_gateway_enabled' => true, 'email_gateway_from_email' => 'billing@example.test', 'email_gateway_from_name' => 'Example ISP', 'email_gateway_reply_to' => '',
        ])->assertSessionHas('flash.type', 'success');
    }

    private function sms(array $values): void
    {
        $this->post('/settings/notifications/sms-gateway', $values + ['sms_gateway_enabled' => true, 'sms_gateway_country_code' => '355'])->assertSessionHas('flash.type', 'success');
    }

    public function test_microsoft_365_sends_through_graph_and_credentials_never_reach_the_page(): void
    {
        $this->actingAsOperator();
        $this->email(['email_gateway_provider' => 'microsoft', 'email_gateway_ms_tenant_id' => 'example.onmicrosoft.com',
            'email_gateway_ms_client_id' => '11111111-2222-3333-4444-555555555555', 'email_gateway_ms_client_secret' => 'ms-secret-value']);

        $this->get('/settings?tab=notifications&view=gateways&gateway=email')->assertOk()->assertInertia(fn ($page) => $page
            ->where('notifications.email.provider', 'microsoft')
            ->where('notifications.email.ms_client_secret_saved', true)
            ->missing('notifications.email.ms_client_secret')
            ->missing('notifications.email.password')
            ->where('notifications.ready.email', true));
        // A blank secret keeps the saved one.
        $this->email(['email_gateway_provider' => 'microsoft', 'email_gateway_ms_tenant_id' => 'example.onmicrosoft.com',
            'email_gateway_ms_client_id' => '11111111-2222-3333-4444-555555555555', 'email_gateway_ms_client_secret' => '']);

        $this->provider([200, '{"access_token":"tok"}'], [202, '']);
        $this->post('/settings/notifications/email-test', ['to' => 'alice@example.test'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('https://login.microsoftonline.com/example.onmicrosoft.com/oauth2/v2.0/token', $this->sent[0]['url']);
        $this->assertStringContainsString('client_secret=ms-secret-value', $this->sent[0]['body']);
        $this->assertSame('https://graph.microsoft.com/v1.0/users/billing%40example.test/sendMail', $this->sent[1]['url']);
        $this->assertSame('Bearer tok', $this->sent[1]['headers']['Authorization']);
        $this->assertSame('alice@example.test', json_decode($this->sent[1]['body'], true)['message']['toRecipients'][0]['emailAddress']['address']);

        $this->provider([401, '{"error":"invalid_client","error_description":"Invalid client secret provided."}']);
        $this->post('/settings/notifications/email-test', ['to' => 'alice@example.test'])
            ->assertSessionHas('flash.message', 'Test not sent: Microsoft sign-in failed: Invalid client secret provided.');
    }

    public function test_mailgun_and_google(): void
    {
        $this->actingAsOperator();
        $this->email(['email_gateway_provider' => 'mailgun', 'email_gateway_mailgun_domain' => 'mg.example.test', 'email_gateway_mailgun_region' => 'eu', 'email_gateway_mailgun_api_key' => 'key-123']);
        $this->provider([200, '{"id":"<1@mg>","message":"Queued. Thank you."}']);
        NotificationService::sendEmail(['email' => 'bob@example.test'], 'Hi', 'Body', true);
        $this->assertSame('https://api.eu.mailgun.net/v3/mg.example.test/messages', $this->sent[0]['url']);
        $this->assertSame('Basic ' . base64_encode('api:key-123'), $this->sent[0]['headers']['Authorization']);
        parse_str($this->sent[0]['body'], $fields);
        $this->assertSame(['Example ISP <billing@example.test>', 'bob@example.test', 'Hi'], [$fields['from'], $fields['to'], $fields['subject']]);

        // Google needs the account and an app password; the SMTP host is built in.
        $this->post('/settings/notifications/email-gateway', ['email_gateway_enabled' => true, 'email_gateway_provider' => 'google', 'email_gateway_from_email' => 'isp@gmail.test', 'email_gateway_username' => 'isp@gmail.test'])
            ->assertSessionHas('flash.message', 'Enter the Google account address and an app password (Google Account → Security → App passwords).');
        $this->email(['email_gateway_provider' => 'google', 'email_gateway_username' => 'isp@gmail.test', 'email_gateway_password' => 'abcd efgh ijkl mnop']);
        $this->assertSame('google', NotificationService::emailGatewaySettings()['provider']);
    }

    public function test_twilio_and_infobip_numbers_and_errors(): void
    {
        $this->actingAsOperator();
        $this->sms(['sms_gateway_provider' => 'twilio', 'sms_gateway_twilio_sid' => 'AC' . str_repeat('a', 32), 'sms_gateway_twilio_token' => 'tw-token', 'sms_gateway_twilio_from' => '+15005550006']);
        $this->provider([201, '{"sid":"SM1","status":"queued"}']);
        $this->post('/settings/notifications/sms-test', ['to' => '069 123 4567'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC' . str_repeat('a', 32) . '/Messages.json', $this->sent[0]['url']);
        parse_str($this->sent[0]['body'], $fields);
        $this->assertSame(['+355691234567', '+15005550006'], [$fields['To'], $fields['From']]);

        $this->sms(['sms_gateway_provider' => 'infobip', 'sms_gateway_infobip_base_url' => 'https://abc123.api.infobip.com', 'sms_gateway_infobip_api_key' => 'ib-key', 'sms_gateway_infobip_from' => 'ExampleISP']);
        $this->provider([200, '{"messages":[{"status":{"groupName":"PENDING"}}]}']);
        NotificationService::sendSms([], '+355 69 123 4567', 'Hello', true);
        $this->assertSame('https://abc123.api.infobip.com/sms/2/text/advanced', $this->sent[0]['url']);
        $this->assertSame('App ib-key', $this->sent[0]['headers']['Authorization']);
        $this->assertSame('355691234567', json_decode($this->sent[0]['body'], true)['messages'][0]['destinations'][0]['to']);

        $this->provider([200, '{"messages":[{"status":{"groupName":"REJECTED","description":"Not enough credits"}}]}']);
        $this->post('/settings/notifications/sms-test', ['to' => '+355691234567'])
            ->assertSessionHas('flash.message', 'Test not sent: Infobip did not accept the message (HTTP 200, REJECTED): Not enough credits');

        // Only Infobip's own hosts are accepted.
        $this->post('/settings/notifications/sms-gateway', ['sms_gateway_enabled' => true, 'sms_gateway_provider' => 'infobip', 'sms_gateway_infobip_base_url' => 'https://api.infobip.com.attacker.test', 'sms_gateway_infobip_from' => 'X'])
            ->assertSessionHas('flash.message', 'Enter your Infobip base URL (xxxxx.api.infobip.com), API key and sender.');
    }

    public function test_international_numbers(): void
    {
        $this->assertSame('+355691234567', SmsProviders::international('069 123 4567', '355'));
        $this->assertSame('+355691234567', SmsProviders::international('69 123 4567', '355'));
        $this->assertSame('+355691234567', SmsProviders::international('355691234567', '355'));
        $this->assertSame('+447700900123', SmsProviders::international('0044 7700 900123', '355'));
        $this->assertSame('+447700900123', SmsProviders::international('+44 (7700) 900-123', '355'));
    }
}
