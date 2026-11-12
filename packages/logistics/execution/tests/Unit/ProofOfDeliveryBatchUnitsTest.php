<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\ScanType;

// POD en lote en el agregado: una confirmación cierra la entrega con varios bultos, sin GPS si no hay señal.

function podbuLoadedDelivery(): Delivery
{
    $delivery = Delivery::start(DeliveryId::generate(), 'SHIP-1', 'VEH-1');
    $device = DeviceContext::of('dev', 'usr');
    $geo = GeoPoint::of(-16.5, -68.1);
    foreach (['U-1', 'U-2'] as $unit) {
        $delivery->recordPreparationScan($unit, $geo, $device, new DateTimeImmutable('2026-11-12T08:00:00Z'), 'prep-'.$unit);
        $delivery->recordLoadScan($unit, $geo, $device, new DateTimeImmutable('2026-11-12T08:30:00Z'), 'load-'.$unit);
    }
    $delivery->releaseEvents();

    return $delivery;
}

it('cierra la entrega con todos los bultos en un solo POD, idempotente', function (): void {
    $delivery = podbuLoadedDelivery();
    $at = new DateTimeImmutable('2026-11-12T11:00:00Z');
    $proof = ProofOfDelivery::of('FIRMA_DIGITAL', null, $at, null, ['pod/a.jpg'], ['ev-1']);

    $delivery->recordProofOfDeliveryForUnits(['U-1', 'U-2', 'U-2'], $proof, null, DeviceContext::of('dev', 'usr'), $at, 'pod-1');
    $delivery->recordProofOfDeliveryForUnits(['U-1', 'U-2'], $proof, null, DeviceContext::of('dev', 'usr'), $at, 'pod-1');

    $podScans = array_values(array_filter($delivery->scans(), static fn ($s): bool => $s->type === ScanType::ProofOfDelivery));
    expect($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and($podScans)->toHaveCount(2)
        ->and($podScans[0]->idempotencyKey)->toBe('pod-1')
        ->and($podScans[1]->idempotencyKey)->toBe('pod-1:U-2')
        ->and($podScans[0]->geo)->toBeNull()
        ->and($delivery->proofOfDelivery()?->photoPath)->toBe('pod/a.jpg');

    $events = $delivery->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(ShipmentDelivered::class)
        ->and($events[0]->payload()['unit_ids'])->toBe(['U-1', 'U-2'])
        ->and($events[0]->payload()['units_delivered'])->toBe(2)
        ->and($events[0]->payload()['evidence_ids'])->toBe(['ev-1']);
});

it('la foto principal se incluye en la lista de fotos del POD', function (): void {
    $proof = ProofOfDelivery::of('X', null, new DateTimeImmutable, 'pod/main.jpg', ['pod/extra.jpg']);

    expect($proof->photoPath)->toBe('pod/main.jpg')
        ->and($proof->photoPaths)->toBe(['pod/main.jpg', 'pod/extra.jpg']);
});
