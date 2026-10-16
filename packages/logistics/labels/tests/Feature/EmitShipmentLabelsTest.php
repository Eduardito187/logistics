<?php

declare(strict_types=1);

use Logistics\Labels\Application\Command\EmitShipmentLabels\EmitShipmentLabels;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Domain\Contract\LabelVerifier;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Labels\Domain\ValueObject\VerificationResult;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Tests\Support\LabelTestEnv;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Flujo completo de emisión de etiquetas de un pedido contra PostgreSQL (logistics_test): el comando
 * arma una etiqueta por bulto físico, la firma, la renderiza en ZPL, la persiste y deja el hecho en el
 * outbox. Cada QR persistido verifica con la clave activa (firma real Ed25519).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    LabelTestEnv::configureSigner();
});

/**
 * @param list<OrderLineInput> $lines
 */
function emitOrder(string $orderNumber, array $lines): void
{
    app(CommandBus::class)->dispatch(new EmitShipmentLabels($orderNumber, 'CD-SCZ', 'C', $lines));
}

it('emits one label per physical piece and persists it', function (): void {
    emitOrder('DM-260012345', [
        new OrderLineInput('LG-GT32WPP', 'Fridge', 2, 3), // 6 bultos
        new OrderLineInput('SAM-TV55', 'TV', 1, 1),       // 1 bulto
    ]);

    expect(EmittedLabelRecord::query()->where('order_id', 'DM-260012345')->count())->toBe(7);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('persists QR content that verifies against the active key, plus its ZPL', function (): void {
    emitOrder('DM-260012346', [new OrderLineInput('LG-GT32WPP', 'Fridge', 1, 1)]);

    /** @var LabelVerifier $verifier */
    $verifier = app(LabelVerifier::class);
    $record = EmittedLabelRecord::query()->where('order_id', 'DM-260012346')->firstOrFail();

    expect($verifier->verify(SignedLabel::fromQrContent($record->signed_json)))->toBe(VerificationResult::Valid)
        ->and($record->zpl)->toStartWith('^XA')
        ->and($record->zpl)->toContain('^FDMA,'.$record->signed_json.'^FS')
        ->and($record->key_id)->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renders the stored ZPL with both the signed QR and the Code128 barcode', function (): void {
    emitOrder('DM-260012350', [new OrderLineInput('LG-GT32WPP', 'Fridge', 1, 1)]);

    $record = EmittedLabelRecord::query()->where('order_id', 'DM-260012350')->firstOrFail();

    // Simbología por defecto `both`: el ZPL guardado (lo que devuelve el endpoint /labels/zpl) lleva el
    // QR firmado (^BQ con el JSON exacto) y el Code128 (^BC) con el id del bulto.
    expect($record->zpl)->toContain('^BQN,2,6')
        ->and($record->zpl)->toContain('^FDMA,'.$record->signed_json.'^FS')
        ->and($record->zpl)->toContain('^BCN,90,Y,N,N')
        ->and($record->zpl)->toContain('^FD'.$record->label_id.'^FS');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('records a labels_emitted fact in the outbox with the batch count', function (): void {
    emitOrder('DM-260012347', [new OrderLineInput('LG-GT32WPP', 'Fridge', 1, 4)]);

    $message = OutboxMessageRecord::query()
        ->where('aggregate_type', 'labels.order')
        ->where('event_name', 'labels.labels_emitted')
        ->where('aggregate_id', 'DM-260012347')
        ->firstOrFail();

    expect($message->payload['label_count'])->toBe(4)
        ->and($message->payload['pre_emitted'])->toBeFalse()
        ->and($message->payload['key_id'])->toBe(1)
        ->and($message->payload['label_ids'])->toHaveCount(4);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('gives every emitted label a unique label id', function (): void {
    emitOrder('DM-260012348', [new OrderLineInput('LG-GT32WPP', 'Fridge', 5, 2)]);

    $ids = EmittedLabelRecord::query()->where('order_id', 'DM-260012348')->pluck('label_id')->all();

    expect($ids)->toHaveCount(10)
        ->and(array_unique($ids))->toHaveCount(10);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
