<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminAlerts;
use App\Support\BillingDocuments;
use App\Support\Branding;
use App\Support\NotificationService;
use Tests\TestCase;

final class CompanyNameTest extends TestCase
{
    public function test_the_company_name_is_used_across_the_portal(): void
    {
        $this->actingAsOperator();
        // Before a name is set: the product name.
        $this->get('/settings?tab=branding')->assertOk()->assertInertia(fn ($page) => $page
            ->where('appName', 'RadiusWeb')
            ->where('branding.name', '')
            ->where('branding.product', 'RadiusWeb'));

        $this->post('/settings/branding/name', ['company_name' => '  Optix   Net  '])->assertRedirect('/settings?tab=branding')->assertSessionHas('flash.type', 'success');
        $this->assertSame('Optix Net', Branding::name());

        $this->get('/settings?tab=branding')->assertInertia(fn ($page) => $page->where('appName', 'Optix Net')->where('branding.name', 'Optix Net'));
        $this->assertSame('Optix Net', BillingDocuments::settings()['company_name']);
        $this->assertSame('Optix Net', NotificationService::emailGatewaySettings()['from_name']);
        $this->assertStringStartsWith('[Optix Net] Daily report', AdminAlerts::report(time())[0]);

        // Signed out, the sign-in page shows it too.
        $this->post('/logout');
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('appName', 'Optix Net'));
    }

    public function test_invalid_names_are_refused_and_empty_goes_back_to_the_product_name(): void
    {
        $this->actingAsOperator();
        $this->post('/settings/branding/name', ['company_name' => str_repeat('A', 61)])->assertSessionHas('flash.type', 'danger');
        $this->post('/settings/branding/name', ['company_name' => '<script>'])->assertSessionHas('flash.type', 'danger');
        $this->assertSame('RadiusWeb', Branding::name());

        $this->post('/settings/branding/name', ['company_name' => 'Optix Net']);
        $this->post('/settings/branding/name', ['company_name' => ''])->assertSessionHas('flash.message', fn (string $m) => str_contains($m, 'RadiusWeb again'));
        $this->assertSame('RadiusWeb', Branding::name());

        $this->actingAsOperator('view');
        $this->post('/settings/branding/name', ['company_name' => 'X'])->assertForbidden();
    }
}
