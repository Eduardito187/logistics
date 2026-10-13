<?php

declare(strict_types=1);

use Logistics\Labels\Application\Command\EmitShipmentLabels\EmitShipmentLabels;
use Logistics\Labels\Application\Command\ReprintOrderLabels\ReprintOrderLabels;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Tests\Support\LabelTestEnv;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reimpresión de etiquetas (ADR 004): revoca las vigentes (quedan en la base para auditoría) y emite
 * un lote nuevo con id nuevos. Nunca borra filas, deja rastro del motivo y publica tanto la revocación
 * como la nueva emisión al outbox.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    LabelTestEnv::configureSigner();

    $lines = [new OrderLineInput('LG-GT32WPP', 'Fridge', 2, 1)];
    app(CommandBus::class)->dispatch(new EmitShipmentLabels('DM-260030001', 'CD-SCZ', 'C', $lines));

    $this->initialIds = EmittedLabelRecord::query()->where('order_id', 'DM-260030001')->pluck('label_id')->all();

    app(CommandBus::class)->dispatch(new ReprintOrderLabels(
        'DM-260030001',
        'CD-SCZ',
        'C',
        $lines,
        'Damaged label in the yard',
    ));
});

it('keeps the old labels revoked with the reason and emits a fresh active batch', function (): void {
    $all = EmittedLabelRecord::query()->where('order_id', 'DM-260030001')->get();

    $revoked = $all->whereNotNull('revoked_at');
    $active = $all->whereNull('revoked_at');

    expect($all)->toHaveCount(4)
        ->and($revoked)->toHaveCount(2)
        ->and($active)->toHaveCount(2)
        ->and($revoked->pluck('revoked_reason')->unique()->all())->toBe(['Damaged label in the yard']);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('gives the reprinted labels brand new ids', function (): void {
    /** @var list<string> $initialIds */
    $initialIds = $this->initialIds;

    $activeIds = EmittedLabelRecord::query()
        ->where('order_id', 'DM-260030001')
        ->whereNull('revoked_at')
        ->pluck('label_id')
        ->all();

    expect(array_intersect($initialIds, $activeIds))->toBe([]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('publishes both the revocation and the new emission to the outbox', function (): void {
    $revoked = OutboxMessageRecord::query()
        ->where('event_name', 'labels.labels_revoked')
        ->where('aggregate_id', 'DM-260030001')
        ->firstOrFail();

    expect($revoked->payload['label_count'])->toBe(2)
        ->and($revoked->payload['reason'])->toBe('Damaged label in the yard')
        // Una emisión por el emit inicial + una por la reimpresión.
        ->and(OutboxMessageRecord::query()
            ->where('event_name', 'labels.labels_emitted')
            ->where('aggregate_id', 'DM-260030001')
            ->count())->toBe(2);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
