<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupSettingsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NasController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SoftwareUpdateController;
use App\Http\Controllers\UserController;
use App\Support\NasTables;
use Illuminate\Support\Facades\Route;

// Same paths as the legacy front controller (public/index.php), so bookmarks and scripts keep working.
// Coarse permissions are enforced here; controllers check finer ones (…manage) as the legacy code did.

Route::get('/uploads/branding/{file}', BrandingAssetController::class)->where('file', '[A-Za-z0-9._-]+');

Route::get('/login', [AuthController::class, 'loginPage']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/two-factor', [AuthController::class, 'twoFactorPage']);
Route::post('/two-factor', [AuthController::class, 'twoFactor']);
Route::get('/two-factor/setup', [AuthController::class, 'twoFactorSetupPage']);
Route::post('/two-factor/setup', [AuthController::class, 'twoFactorSetup']);
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware('frweb.auth')->group(function () {
    Route::get('/reauth', [AuthController::class, 'reauthPage']);
    Route::post('/reauth', [AuthController::class, 'reauth']);

    Route::middleware('permission:dashboard.view')->group(function () {
        Route::get('/', [DashboardController::class, 'dashboard']);
        Route::get('/dashboard', [DashboardController::class, 'dashboard']);
        Route::get('/fragments/{fragment}', [DashboardController::class, 'fragment'])->whereIn('fragment', ['dashboard-kpis', 'dashboard-nas', 'dashboard-rejects']);
    });

    // Profile (every signed-in operator).
    Route::get('/profile', [ProfileController::class, 'profile']);
    Route::post('/profile/save', [ProfileController::class, 'save']);
    Route::post('/profile/language', [ProfileController::class, 'language']);
    Route::middleware('recent-auth')->group(function () {
        Route::post('/profile/password', [ProfileController::class, 'password']);
        Route::get('/profile/2fa/setup', [ProfileController::class, 'twoFactorSetup']);
        Route::post('/profile/2fa/start', [ProfileController::class, 'twoFactorStart']);
        Route::post('/profile/2fa/enable', [ProfileController::class, 'twoFactorEnable']);
        Route::post('/profile/2fa/disable', [ProfileController::class, 'twoFactorDisable']);
    });

    // Settings and portal users (settings.manage: superadmin).
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'settings']);
        Route::get('/docs', [SettingsController::class, 'docs']);
        Route::get('/settings/notifications/{channel}', [SettingsController::class, 'legacyNotificationPage'])->whereIn('channel', ['general', 'sms', 'email']);
        Route::post('/settings/branding/name', [SettingsController::class, 'saveCompanyName']);
        Route::post('/settings/branding/logo', [SettingsController::class, 'saveLogo']);
        Route::post('/settings/branding/logo/delete', [SettingsController::class, 'deleteLogo']);
        Route::post('/settings/branding/logo/url', [SettingsController::class, 'saveLogoUrl']);
        Route::post('/settings/notifications/templates', [SettingsController::class, 'saveNotificationTemplates']);
        Route::post('/settings/notifications/bulk-preview', [SettingsController::class, 'notificationBulkPreview']);
        Route::post('/settings/notifications/bulk-confirm', [SettingsController::class, 'notificationBulkConfirm']);
        Route::post('/settings/notifications/{channel}-test', [SettingsController::class, 'testGateway'])->whereIn('channel', ['email', 'sms']);
        Route::post('/settings/notifications/admin/telegram-chats', [SettingsController::class, 'telegramChats']);
        Route::post('/settings/notifications/admin/{kind}', [SettingsController::class, 'sendAdminAlert'])->whereIn('kind', ['test', 'report']);
        Route::post('/settings/documents', [SettingsController::class, 'saveDocuments']);
        Route::get('/settings/documents/preview/{kind}', [SettingsController::class, 'previewDocument'])->whereIn('kind', ['request', 'receipt']);
        Route::post('/settings/backups/run', [BackupSettingsController::class, 'runNow']);
        Route::post('/settings/update/check', [SoftwareUpdateController::class, 'check']);
        Route::get('/settings/update/status', [SoftwareUpdateController::class, 'status']);
        Route::post('/settings/backups/destinations/{id}/test', [BackupSettingsController::class, 'test'])->where('id', '[0-9a-f]{12}');
        foreach (['general', 'sms-template', 'email-template', 'send-sms'] as $legacy) {
            Route::post('/settings/notifications/' . $legacy, [SettingsController::class, 'notificationLegacy']);
        }
        Route::middleware('recent-auth')->group(function () {
            Route::post('/settings/notifications/sms-gateway', [SettingsController::class, 'saveSmsGateway']);
            Route::post('/settings/notifications/email-gateway', [SettingsController::class, 'saveEmailGateway']);
            Route::post('/settings/notifications/admin', [SettingsController::class, 'saveAdminAlerts']);
            Route::post('/settings/retention', [SettingsController::class, 'saveRetention']);
            Route::post('/settings/currency', [SettingsController::class, 'saveCurrency']);
            Route::post('/settings/timezone', [SettingsController::class, 'saveTimezone']);
            Route::post('/settings/backups/passphrase', [BackupSettingsController::class, 'passphrase']);
            Route::post('/settings/update/start', [SoftwareUpdateController::class, 'start']);
            Route::post('/settings/backups/local', [BackupSettingsController::class, 'local']);
            Route::post('/settings/backups/destinations', [BackupSettingsController::class, 'save']);
            Route::post('/settings/backups/destinations/{id}/delete', [BackupSettingsController::class, 'delete'])->where('id', '[0-9a-f]{12}');
        });

        foreach (['/settings/users', '/portal-users'] as $base) {
            Route::get($base, [UserController::class, 'index']);
            Route::get($base . '/create', [UserController::class, 'form']);
            Route::get($base . '/{id}/edit', [UserController::class, 'form'])->whereNumber('id');
            Route::middleware('recent-auth')->group(function () use ($base) {
                Route::post($base . '/save', [UserController::class, 'save']);
                Route::post($base . '/{id}/delete', [UserController::class, 'delete'])->whereNumber('id');
            });
        }
    });

    // Clients.
    Route::middleware('permission:clients.view')->group(function () {
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/export', [ClientController::class, 'export']);
        Route::get('/clients/create', [ClientController::class, 'form']);
        Route::get('/clients/{username}/edit', [ClientController::class, 'form']);
        Route::get('/clients/{username}/overview', [ClientController::class, 'overview']);
        Route::get('/clients/{username}/usage', [ClientController::class, 'usage']);
        Route::get('/clients/{username}/diagnostics', [ClientController::class, 'diagnostics']);
        Route::post('/clients/save', [ClientController::class, 'save']);
        Route::post('/clients/bulk', [ClientController::class, 'bulk']);
        Route::post('/clients/{username}/suspension', [ClientController::class, 'suspension'])->middleware('recent-auth');
        Route::post('/clients/{username}/delete', [ClientController::class, 'delete']);
        Route::post('/clients/{username}/disconnect', [ClientController::class, 'disconnect']);
        Route::post('/clients/{username}/reveal-password', [ClientController::class, 'revealPassword'])->middleware('recent-auth');
        Route::post('/documents/{id}/delete', [ClientController::class, 'documentDelete'])->whereNumber('id');
        Route::get('/documents/{id}/download', [ClientController::class, 'documentDownload'])->whereNumber('id');
    });

    // Network operations queue.
    Route::middleware('permission:operations.view')->group(function () {
        Route::get('/operations', [OperationsController::class, 'index']);
        Route::post('/operations/{id}/retry', [OperationsController::class, 'retry'])->whereNumber('id')->middleware('recent-auth');
    });

    // Services and static IP pools.
    Route::middleware('permission:services.view')->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/create', [ServiceController::class, 'form']);
        Route::get('/services/{id}/edit', [ServiceController::class, 'form'])->whereNumber('id');
        Route::post('/services/save', [ServiceController::class, 'save']);
        Route::post('/services/{id}/delete', [ServiceController::class, 'delete'])->whereNumber('id');
        Route::get('/services/ip-pools', [ServiceController::class, 'pools']);
        Route::get('/services/ip-pools/create', [ServiceController::class, 'poolForm']);
        Route::get('/services/ip-pools/{id}/edit', [ServiceController::class, 'poolForm'])->whereNumber('id');
        Route::post('/services/ip-pools/save', [ServiceController::class, 'poolSave']);
        Route::post('/services/ip-pools/{id}/delete', [ServiceController::class, 'poolDelete'])->whereNumber('id');
    });

    // NAS devices and monitoring.
    Route::middleware('permission:nas.view')->group(function () {
        Route::get('/nas', [NasController::class, 'index']);
        Route::get('/nas/health', [NasController::class, 'health']);
        Route::get('/nas/create', [NasController::class, 'form']);
        Route::get('/nas/{id}/edit', [NasController::class, 'form'])->whereNumber('id');
        // The Access tab (access profiles) was removed; old links open the NAS page.
        Route::get('/nas/{id}/access', fn (string $id) => redirect('/nas/' . (int) $id . '/edit'))->whereNumber('id');
        Route::middleware('recent-auth')->group(function () {
            Route::post('/nas/{id}/snmp', [NasController::class, 'snmpSave'])->whereNumber('id');
            Route::post('/nas/save', [NasController::class, 'save']);
            Route::post('/nas/{id}/delete', [NasController::class, 'delete'])->whereNumber('id');
        });

        Route::get('/monitoring', [MonitoringController::class, 'index']);
        Route::get('/monitoring/live', [MonitoringController::class, 'live']);
        Route::get('/monitoring/server', [MonitoringController::class, 'server']);
        Route::get('/monitoring/server/live', [MonitoringController::class, 'serverLive']);
        Route::post('/monitoring/server/restart', [MonitoringController::class, 'restartService'])->middleware(['permission:settings.manage', 'recent-auth']);
        Route::post('/monitoring/poll', [MonitoringController::class, 'poll']);
        Route::get('/nas/{id}/monitor', [MonitoringController::class, 'nas'])->whereNumber('id');
        Route::get('/nas/{id}/monitor/live', [MonitoringController::class, 'nasLive'])->whereNumber('id');
        Route::get('/nas/{id}/monitor/tables/{table}', [MonitoringController::class, 'nasTable'])->whereNumber('id')->whereIn('table', array_keys(NasTables::TABLES));
        Route::get('/nas/{id}/monitor/graph', [MonitoringController::class, 'trafficGraph'])->whereNumber('id');
        Route::get('/nas/{id}/monitor/health', [MonitoringController::class, 'healthGraph'])->whereNumber('id');
        Route::get('/nas/{id}/monitor/temperature', [MonitoringController::class, 'temperatureGraph'])->whereNumber('id');
    });

    // Billing.
    Route::middleware('permission:billing.view')->group(function () {
        Route::get('/billing', [BillingController::class, 'index']);
        Route::get('/billing/export', [BillingController::class, 'export']);
        Route::get('/billing/invoices/create', [BillingController::class, 'form']);
        Route::get('/billing/invoices/{id}', [BillingController::class, 'show'])->whereNumber('id');
        Route::get('/billing/invoices/{id}/edit', [BillingController::class, 'form'])->whereNumber('id');
        Route::get('/billing/invoices/{id}/draft-pdf', [BillingController::class, 'draftPdf'])->whereNumber('id');
        Route::get('/billing/invoices/{id}/payment-pdf', [BillingController::class, 'paymentPdf'])->whereNumber('id');
        Route::post('/billing/invoices/save', [BillingController::class, 'save']);
        Route::post('/billing/invoices/{id}/payment', [BillingController::class, 'paymentSave'])->whereNumber('id');
        Route::post('/billing/invoices/{id}/payment/new', [BillingController::class, 'paymentStart'])->whereNumber('id');
        Route::post('/billing/invoices/{id}/classify', [BillingController::class, 'classify'])->whereNumber('id')->middleware('recent-auth');
        Route::post('/billing/invoices/{id}/void', [BillingController::class, 'void'])->whereNumber('id');
        Route::post('/billing/invoices/{id}/delete', [BillingController::class, 'delete'])->whereNumber('id');
        Route::post('/billing/generate', [BillingController::class, 'generate']);
    });

    Route::get('/reports', [ReportsController::class, 'reports'])->middleware('permission:reports.view');
    Route::get('/logs', [ReportsController::class, 'logs'])->middleware('permission:logs.view');
});
