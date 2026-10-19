<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminAlerts;
use App\Support\BillingDocuments;
use App\Support\Db;
use App\Support\ProviderHttp;
use Tests\TestCase;

final class DocumentTemplatesTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderHttp::fake(null);
        parent::tearDown();
    }

    /** The text the PDF draws, as one string (text operators are "(...) Tj"; the fonts use Windows-1252). */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $pdf, $m);
        $raw = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], implode("\n", $m[1]));
        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    public function test_invoice_and_receipt_wording_is_saved_and_used_in_the_pdfs(): void
    {
        $this->actingAsOperator();
        $this->get('/settings?tab=documents')->assertOk()->assertInertia(fn ($page) => $page
            ->where('documents.values.draft_title', '')
            ->where('documents.defaults.draft_title', 'DRAFT PAYMENT REQUEST')
            ->where('documents.lines.company_details', 4));

        // Defaults until something is saved.
        $text = $this->pdfText((string) $this->get('/settings/documents/preview/request')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
        $this->assertStringContainsString('DRAFT PAYMENT REQUEST', $text);
        $this->assertStringContainsString('This document is a payment request, not a receipt.', $text);

        $this->post('/settings/documents', [
            'company_name' => 'Optix Net Shpk',
            'company_details' => "Rruga e Durrësit 12, Tiranë\nNIPT L12345678A\n+355 69 000 0000",
            'draft_title' => 'FATURË PËR PAGESË',
            'draft_note' => "Paguani {balance} deri më {due}.\nIBAN AL47 2121 1009 0000 0002 3569 8741",
            'receipt_title' => 'MANDAT PAGESE',
            'receipt_note' => 'Faleminderit, {customer}!',
        ])->assertRedirect('/settings?tab=documents')->assertSessionHas('flash.type', 'success');

        $request = $this->pdfText((string) $this->get('/settings/documents/preview/request')->getContent());
        foreach (['Optix Net Shpk', 'Rruga e Durrësit 12, Tiranë', 'NIPT L12345678A', 'FATURË PËR PAGESË', 'IBAN AL47 2121 1009 0000 0002 3569 8741',
            'This document is a payment request, not a receipt.'] as $expected) {
            $this->assertStringContainsString($expected, $request);
        }
        $this->assertMatchesRegularExpression('/Paguani 20\.00 [A-Z]{3} deri më \d{4}-\d\d-\d\d\./', $request);

        $receipt = $this->pdfText((string) $this->get('/settings/documents/preview/receipt')->getContent());
        $this->assertStringContainsString('MANDAT PAGESE', $receipt);
        $this->assertStringContainsString('Faleminderit, Arben Hoxha!', $receipt);
        $this->assertStringContainsString('Confirms recorded payments only', $receipt);

        // The euro sign survives (Windows-1252 0x80).
        $this->post('/settings/documents', ['draft_note' => 'Totali: 20 €'])->assertSessionHas('flash.type', 'success');
        $this->assertStringContainsString('Totali: 20 €', $this->pdfText((string) $this->get('/settings/documents/preview/request')->getContent()));
        // Saving with a field empty brings back its default.
        $this->assertSame('', BillingDocuments::saved()['company_name']);
    }

    public function test_invalid_wording_is_refused(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/documents', ['company_details' => "1\n2\n3\n4\n5"])->assertSessionHas('flash.message', 'Company details: at most 4 lines.');
        $this->post('/settings/documents', ['draft_note' => 'Pay {amount}'])->assertSessionHas('flash.message', 'Payment instructions: unknown placeholder {amount}.');
        $this->post('/settings/documents', ['draft_title' => str_repeat('A', 41)])->assertSessionHas('flash.message', 'Payment request title: at most 40 characters.');
        $this->assertFalse((bool) Db::value('SELECT COUNT(*) FROM settings WHERE setting_key LIKE "billing_pdf_%"'));

        $this->actingAsOperator('view');
        $this->post('/settings/documents', ['company_name' => 'X'])->assertForbidden();
        $this->get('/settings/documents/preview/request')->assertForbidden();
    }

    public function test_admin_alert_templates_shape_the_messages(): void
    {
        $sent = [];
        ProviderHttp::fake(function (string $method, string $url, array $headers, $body) use (&$sent): array {
            $sent[] = (string) (json_decode((string) $body, true)['text'] ?? '');
            return [200, '{"ok":true}'];
        });
        $base = ['enabled' => true, 'alert_telegram' => true, 'telegram_token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1', 'telegram_chats' => '111',
            'checks' => ['jobs'], 'report_time' => '07:00', 'report_enabled' => true, 'report_telegram' => true];

        // A placeholder from the report is not available in an alert.
        try {
            AdminAlerts::save($base + ['templates' => ['alert_subject' => 'Alarm {date}']]);
            $this->fail('An alert template with {date} was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('{date} cannot be used here', $e->getMessage());
        }

        AdminAlerts::save($base + ['templates' => [
            'alert_subject' => 'ALARM {app}: {summary}',
            'alert_body' => "Përshëndetje,\n{events}\n{open}",
            'report_subject' => 'Raporti ditor {date} ({status})',
            'report_intro' => 'Raport automatik nga {host}.',
        ]]);
        $this->assertSame('ALARM {app}: {summary}', AdminAlerts::settings()['templates']['alert_subject']);

        // A stuck network worker, seen on two checks in a row.
        Db::exec('SET FOREIGN_KEY_CHECKS=0');
        $id = $this->insertRow('network_operations', ['subscription_id' => 3, 'policy_revision' => 1, 'action' => 'apply', 'dedupe_key' => 'k1', 'state' => 'pending']);
        Db::exec('UPDATE network_operations SET created_at = NOW() - INTERVAL 20 MINUTE WHERE id=?', [$id]);
        Db::exec('SET FOREIGN_KEY_CHECKS=1');
        $day = mktime(6, 0, 0, 3, 10, 2026);
        AdminAlerts::run($day);
        AdminAlerts::run($day + 60);
        $this->assertCount(1, $sent);
        $this->assertMatchesRegularExpression('/^ALARM .+: 1 problem\n\nPërshëndetje,\nPROBLEM: Network worker.*\nStill open:\n- Network worker/s', $sent[0]);
        $this->assertStringNotContainsString('Server:', $sent[0]);

        AdminAlerts::run($day + 3600);
        $report = end($sent);
        $this->assertStringStartsWith("Raporti ditor 2026-03-10 (1 open problem)\n\nRaport automatik nga ", $report);
        $this->assertStringContainsString('BACKGROUND JOBS', $report);
    }
}
