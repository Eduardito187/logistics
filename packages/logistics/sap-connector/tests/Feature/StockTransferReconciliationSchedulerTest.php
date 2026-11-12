<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;

/**
 * Sin doble push: el scheduler (`logistics:sap:dispatch-transfers`) reintenta SOLO los traspasos Failed
 * cuyo backoff venció; nunca toma los Pending (esos los empuja únicamente el reactor del outbox).
 * Contra PostgreSQL (logistics_test), con el stub Null de SAP (no falla).
 */
beforeEach(function (): void {
    config()->set('logistics-sap-connector.transfers.retry_backoff_minutes', 5);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('el scheduler reintenta solo los Failed con backoff vencido y nunca los Pending', function (): void {
    $pending = StockTransferRecord::factory()->pending()->create([
        'id' => StockTransferId::generate()->toString(),
        'event_id' => StockTransferId::generate()->toString(),
        'updated_at' => now()->subHour(),
    ]);
    $failedDue = StockTransferRecord::factory()->failed()->create([
        'id' => StockTransferId::generate()->toString(),
        'event_id' => StockTransferId::generate()->toString(),
        'updated_at' => now()->subMinutes(10),
    ]);
    $failedFresh = StockTransferRecord::factory()->failed()->create([
        'id' => StockTransferId::generate()->toString(),
        'event_id' => StockTransferId::generate()->toString(),
        'updated_at' => now(),
    ]);

    $this->artisan('logistics:sap:dispatch-transfers')->assertSuccessful();

    $status = static fn (StockTransferRecord $record): ?string => StockTransferRecord::query()->whereKey($record->getKey())->value('status');

    expect($status($pending))->toBe(TransferStatus::Pending->value)
        ->and($status($failedDue))->toBe(TransferStatus::Sent->value)
        ->and($status($failedFresh))->toBe(TransferStatus::Failed->value);
});
