<?php
declare(strict_types=1);
namespace App\Support;
final class DemoSeeder
{
    public static function run(): void
    {
        if (!in_array(App::env('APP_ENV'), ['test','development'],true)) { throw new \RuntimeException('Demo seeding is restricted to development/test environments.'); }
        $lock='frweb:demo-seed:'.substr(hash('sha256',(string)Db::value('SELECT DATABASE()')),0,40);
        if((int)Db::value('SELECT GET_LOCK(?,0)',[$lock])!==1){throw new \RuntimeException('Another demo seed is running.');}
        try {
            if ((int) Db::value('SELECT COUNT(*) FROM portal_users WHERE username = ?', ['superadmin']) === 0) {
                Db::exec(
                    'INSERT INTO portal_users (name, email, username, password_hash, role, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, "superadmin", 1, NOW(), NOW())',
                    ['Super Administrator', 'admin@example.com', 'superadmin', password_hash('ChangeMe!12345', PASSWORD_DEFAULT)]
                );
            }
            
            Db::exec(
                'INSERT INTO settings (setting_key, setting_value, is_secret, updated_by, updated_at)
                 VALUES ("web_server_engine", "nginx", 0, NULL, NOW())
                 ON DUPLICATE KEY UPDATE setting_key=setting_key'
            );
            
            if (!Db::value('SELECT id FROM service_metadata WHERE group_name=?', ['expired'])) {
            RadiusRepository::saveService([
                'group_name' => 'expired',
                'name' => 'Expired Service',
                'description' => 'Default PPPoE service automatically applied to expired accounts.',
                'enabled' => '1',
                'download_speed' => '256k',
                'upload_speed' => '256k',
                'mikrotik_rate_limit' => '256k/256k',
                'burst_limit' => '',
                'burst_threshold' => '',
                'burst_time' => '',
                'priority' => '8',
                'ip_mode' => 'none',
                'ip_pool' => '',
                'session_timeout' => '',
                'idle_timeout' => '',
                'simultaneous_use' => '1',
                'download_quota' => '',
                'upload_quota' => '',
                'total_quota' => '',
                'expiration_behavior' => 'expired captive/limited service',
                'billing_mode' => 'prepaid',
                'expired_service_group' => '',
                'price' => '0.00',
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'custom_group_attrs' => '',
            ], null);
            }
            
            
            if (!Db::value('SELECT id FROM service_metadata WHERE group_name=?', ['pppoe_20m'])) {
            RadiusRepository::saveService([
                'group_name' => 'pppoe_20m',
                'name' => 'PPPoE 20M Residential',
                'description' => 'Sample 20 Mbps down / 5 Mbps up residential plan.',
                'enabled' => '1',
                'download_speed' => '20M',
                'upload_speed' => '5M',
                'mikrotik_rate_limit' => '5M/20M',
                'burst_limit' => '',
                'burst_threshold' => '',
                'burst_time' => '',
                'priority' => '8',
                'ip_mode' => 'pool',
                'ip_pool' => 'pppoe_pool',
                'session_timeout' => '',
                'idle_timeout' => '',
                'simultaneous_use' => '1',
                'download_quota' => '',
                'upload_quota' => '',
                'total_quota' => '',
                'expiration_behavior' => 'reject after expiration',
                'billing_mode' => 'prepaid',
                'expired_service_group' => 'expired',
                'price' => '29.99',
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'custom_group_attrs' => '',
            ], null);
            }
            
            
            if ((int) Db::value('SELECT COUNT(*) FROM nas WHERE nasname = ?', ['192.0.2.10']) === 0) {
                RadiusRepository::saveNas([
                    'nasname' => '192.0.2.10',
                    'shortname' => 'mk-bras-01',
                    'type' => 'mikrotik',
                    'vendor' => 'mikrotik',
                    'ports' => '0',
                    'secret' => 'testing123',
                    'server' => '',
                    'community' => '',
                    'description' => 'Sample MikroTik PPPoE NAS. Replace IP and secret.',
                    'enabled' => '1',
                    'coa_enabled' => '0',
                    'coa_port' => '3799',
                    'api_host' => '',
                    'api_user' => '',
                    'api_password' => '',
                ]);
            }
            
            if (!Db::value('SELECT username FROM customer_profiles WHERE username=?', ['demo-pppoe']) && !Db::value('SELECT username FROM customer_identity_history WHERE username=?',['demo-pppoe'])) {
            RadiusRepository::saveClient([
                'username' => 'demo-pppoe',
                'password' => 'demo12345',
                'enabled' => '1',
                'verified' => '1',
                'email_alert' => '1',
                'sms_alert' => '0',
                'account_status' => 'active',
                'expiry_date' => date('Y-m-d', strtotime('+30 days')),
                'available_online_time' => '',
                'simultaneous_use' => '1',
                'service_group' => 'pppoe_20m',
                'owner_operator_id' => null,
                'ip_mode' => 'pool',
                'framed_ip_address' => '',
                'framed_pool' => 'pppoe_pool',
                'mac_address' => '',
                'mac_lock' => '0',
                'nas_restriction_type' => 'all',
                'nas_restriction_value' => '',
                'first_name' => 'Demo',
                'last_name' => 'Client',
                'company_name' => '',
                'address' => '123 Example Street',
                'city' => 'Example City',
                'state' => '',
                'zip' => '',
                'country' => 'US',
                'phone' => '+1 555 0100',
                'cell_phone' => '',
                'email' => 'demo@example.com',
                'vat_tax_id' => '',
                'contract_id' => 'DEMO-001',
                'contract_valid_until' => date('Y-m-d', strtotime('+1 year')),
                'gps' => '',
                'language' => 'en',
                'notes' => 'Seeded sample PPPoE customer.',
                'download_limit' => '',
                'upload_limit' => '',
                'total_traffic_limit' => '',
                'balance' => '0.00',
                'invoice_alert' => '0',
                'payment_status' => 'current',
                'billing_mode' => 'prepaid',
                'custom_radius_attributes' => '',
            ], null);
            }
            
            
        } finally { Db::value('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
}
