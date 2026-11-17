<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\CorrectShipmentVisibleState\CorrectShipmentVisibleState;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

// Corrección MANUAL del estado visible (ola 0): reutiliza el avance tolerante, respeta la máquina de
// estados, solo admite estados operativos y deja la auditoría en la bitácora. Contra logistics_test.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Abre un seguimiento, le liga un envío y lo lleva hasta IN_PROGRESS. Devuelve [orderReference, shipmentId].
 *
 * @return array{0: string, 1: string}
 */
function csvsInProgress(): array
{
    $order = 'ORD-CSVS-'.Str::random(8);
    $shipment = (string) Str::uuid7();
    $bus = app(CommandBus::class);
    $bus->dispatch(new OpenTracking(orderReference: $order));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $order));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::InProgress, orderReference: $order));

    return [$order, $shipment];
}

it('aplica LOADED desde IN_PROGRESS y deja la corrección en la bitácora', function (): void {
    [, $shipment] = csvsInProgress();

    app(CommandBus::class)->dispatch(new CorrectShipmentVisibleState(
        shipmentId: $shipment,
        orderReference: null,
        targetStatus: 'LOADED',
        reason: 'carga confirmada por la tienda',
        actorId: 'user-1',
        actorName: 'Operador Uno',
        notifyCustomer: true,
    ));

    expect(app(TrackingRepository::class)->findByShipmentId($shipment)?->status())
        ->toBe(VisibleStatus::Loaded);

    $audit = DB::table('outbox_messages')
        ->where('event_name', 'tracking.shipment_corrected')
        ->get();

    expect($audit)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $audit[0]->payload, true);
    expect($payload['shipment_id'])->toBe($shipment)
        ->and($payload['from'])->toBe('IN_PROGRESS')
        ->and($payload['to'])->toBe('LOADED')
        ->and($payload['reason'])->toBe('carga confirmada por la tienda')
        ->and($payload['actor_id'])->toBe('user-1')
        ->and($payload['actor_name'])->toBe('Operador Uno')
        ->and($payload['notify_customer'])->toBeTrue();
});

it('rechaza corregir a un estado gobernado por su flujo real (no corregible a mano)', function (string $status): void {
    [, $shipment] = csvsInProgress();

    expect(fn () => app(CommandBus::class)->dispatch(new CorrectShipmentVisibleState(
        shipmentId: $shipment,
        orderReference: null,
        targetStatus: $status,
        reason: 'motivo',
        actorId: 'user-1',
        actorName: 'Operador Uno',
    )))->toThrow(TrackingException::class, 'no corregible');

    // El estado no se movió y no quedó auditoría.
    expect(app(TrackingRepository::class)->findByShipmentId($shipment)?->status())
        ->toBe(VisibleStatus::InProgress)
        ->and(DB::table('outbox_messages')->where('event_name', 'tracking.shipment_corrected')->count())
        ->toBe(0);
})->with(['DELIVERED', 'CANCELLED', 'RETURNED', 'PENDING']);
