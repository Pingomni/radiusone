<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Http\Support\Present;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Db;
use App\Support\JobHealth;
use App\Support\NetworkOperations;
use App\Support\SchemaCompatibility;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Network operations: the outbox of router changes queued by the portal. */
final class OperationsController extends Controller
{
    public function index(): Response
    {
        SchemaCompatibility::require('policy');
        $lag = NetworkOperations::lag();
        $worker = JobHealth::all()['network'];
        $canRetry = Auth::can('operations.manage');
        $rows = Db::select('SELECT o.*,c.username FROM network_operations o JOIN subscriptions s ON s.id=o.subscription_id JOIN customer_profiles c ON c.id=s.customer_id ORDER BY o.id DESC LIMIT 100');

        return Inertia::render('operations/Index', [
            'worker' => ['stuck' => $worker['state'] === 'stuck', 'detail' => (string) $worker['detail']],
            'metrics' => [
                Present::metric('Outstanding operations', (int) $lag['outstanding'], 'signal', (int) $lag['outstanding'] > 0 ? 'warning' : ''),
                Present::metric('Need attention', (int) ($lag['needs_attention'] ?? 0), 'shield', (int) ($lag['needs_attention'] ?? 0) > 0 ? 'danger' : ''),
                Present::metric('Oldest outstanding', Format::seconds((int) $lag['oldest_seconds']), 'reports'),
            ],
            'operations' => array_map(static fn (array $o) => [
                'id' => (int) $o['id'],
                'username' => (string) $o['username'],
                'action' => (string) $o['action'],
                'revision' => (string) $o['policy_revision'],
                'state' => Present::tag((string) $o['state'], self::tone((string) $o['state']) ?: null),
                'result' => (string) ($o['result_code'] ?: '-'),
                'created' => (string) ($o['created_at'] ?? ''),
                'retry' => $canRetry && in_array($o['state'], ['blocked', 'nak', 'failed'], true),
            ], $rows),
        ]);
    }

    public function retry(string $id): RedirectResponse
    {
        Auth::requireCan('operations.manage');
        SchemaCompatibility::require('policy');
        // Preserve session snapshot and attempt budget. Every retry observes before sending.
        Db::exec('UPDATE network_operations SET state="uncertain",available_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND state IN ("blocked","nak","failed") AND attempts<5', [(int) $id]);
        Audit::log('retry_network_operation', 'network_operation', $id);
        return $this->ok('/operations', 'Reconciliation queued. The original session must be verified before retry.');
    }

    /** Done is the normal outcome: plain text. */
    public static function tone(string $state): string
    {
        return match ($state) {
            'failed', 'nak', 'blocked' => 'danger',
            'pending', 'running', 'ack', 'timeout', 'uncertain' => 'warning',
            default => '',
        };
    }
}
