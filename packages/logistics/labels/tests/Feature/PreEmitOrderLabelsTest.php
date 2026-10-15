<?php

declare(strict_types=1);

use Logistics\Labels\Application\Command\PreEmitOrderLabels\PreEmitOrderLabels;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Tests\Support\LabelTestEnv;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Pre-emisión de etiquetas al planificar (la dispara el worker al publicarse un plan). Debe dejar las
 * etiquetas listas antes de preparar el pedido y ser idempotente: reentregar el evento no crea bultos
 * duplicados ni un segundo hecho en el outbox.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    LabelTestEnv::configureSigner();
});

function preEmit(string $orderNumber): void
{
    app(CommandBus::class)->dispatch(new PreEmitOrderLabels(
        $orderNumber,
        'CD-SCZ',
        'C',
        [new OrderLineInput('LG-GT32WPP', 'Fridge', 3, 1)],
    ));
}

it('pre-emits the order labels marked as pre-emitted in the outbox', function (): void {
    preEmit('DM-260020001');

    expect(EmittedLabelRecord::query()->where('order_id', 'DM-260020001')->count())->toBe(3);

    $message = OutboxMessageRecord::query()
        ->where('aggregate_type', 'labels.order')
        ->where('event_name', 'labels.labels_emitted')
        ->where('aggregate_id', 'DM-260020001')
        ->firstOrFail();

    expect($message->payload['pre_emitted'])->toBeTrue()
        ->and($message->payload['label_count'])->toBe(3);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('is idempotent: re-running does not duplicate labels nor the outbox fact', function (): void {
    preEmit('DM-260020002');
    preEmit('DM-260020002');

    expect(EmittedLabelRecord::query()->where('order_id', 'DM-260020002')->count())->toBe(3)
        ->and(OutboxMessageRecord::query()
            ->where('event_name', 'labels.labels_emitted')
            ->where('aggregate_id', 'DM-260020002')
            ->count())->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
