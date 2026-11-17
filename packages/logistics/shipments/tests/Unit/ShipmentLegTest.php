<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\Model\ShipmentLeg;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\LegStatus;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLegId;

it('builds a leg with sensible defaults (own fleet, pending)', function (): void {
    $leg = ShipmentLeg::create(ShipmentLegId::generate(), 1);

    expect($leg->seq())->toBe(1)
        ->and($leg->haulageMode())->toBe(HaulageMode::OwnFleet)
        ->and($leg->status())->toBe(LegStatus::Pending)
        ->and($leg->carrierId())->toBeNull()
        ->and($leg->fromNodeId())->toBeNull()
        ->and($leg->toNodeId())->toBeNull()
        ->and($leg->departedAt())->toBeNull()
        ->and($leg->arrivedAt())->toBeNull();
});

it('normalises node and carrier ids (trim + lowercase, empty to null)', function (): void {
    $leg = ShipmentLeg::create(
        ShipmentLegId::generate(),
        2,
        '  3F2504E0-4F89-41D3-9A0C-0305E82C3301  ',
        '',
        '  santa_cruz  ',
        '  tarija  ',
        HaulageMode::ThirdParty,
        '  9B2504E0-4F89-41D3-9A0C-0305E82C3399  ',
        LegStatus::InTransit,
    );

    expect($leg->fromNodeId())->toBe('3f2504e0-4f89-41d3-9a0c-0305e82c3301')
        ->and($leg->toNodeId())->toBeNull()
        ->and($leg->fromDepartment())->toBe('santa_cruz')
        ->and($leg->toDepartment())->toBe('tarija')
        ->and($leg->carrierId())->toBe('9b2504e0-4f89-41d3-9a0c-0305e82c3399')
        ->and($leg->haulageMode())->toBe(HaulageMode::ThirdParty)
        ->and($leg->status())->toBe(LegStatus::InTransit);
});

it('rejects a leg with a sequence below one', function (): void {
    expect(fn () => ShipmentLeg::create(ShipmentLegId::generate(), 0))
        ->toThrow(ShipmentsException::class, 'secuencia del tramo');
});

it('orders the itinerary legs by sequence regardless of input order', function (): void {
    $shipmentId = ShipmentId::generate();
    $third = ShipmentLeg::create(ShipmentLegId::generate(), 3);
    $first = ShipmentLeg::create(ShipmentLegId::generate(), 1);
    $second = ShipmentLeg::create(ShipmentLegId::generate(), 2);

    $itinerary = ShipmentItinerary::fromLegs($shipmentId, [$third, $first, $second]);

    expect($itinerary->count())->toBe(3)
        ->and($itinerary->isEmpty())->toBeFalse()
        ->and($itinerary->shipmentId()->equals($shipmentId))->toBeTrue()
        ->and(array_map(fn (ShipmentLeg $l): int => $l->seq(), $itinerary->legs()))->toBe([1, 2, 3]);
});

it('builds an empty itinerary', function (): void {
    $itinerary = ShipmentItinerary::fromLegs(ShipmentId::generate(), []);

    expect($itinerary->isEmpty())->toBeTrue()
        ->and($itinerary->count())->toBe(0)
        ->and($itinerary->legs())->toBe([]);
});

it('rejects an itinerary with a repeated sequence', function (): void {
    $shipmentId = ShipmentId::generate();
    $a = ShipmentLeg::create(ShipmentLegId::generate(), 1);
    $b = ShipmentLeg::create(ShipmentLegId::generate(), 1);

    expect(fn () => ShipmentItinerary::fromLegs($shipmentId, [$a, $b]))
        ->toThrow(ShipmentsException::class, 'secuencia de tramo repetida');
});

it('resolves haulage mode and leg status case-insensitively', function (): void {
    expect(HaulageMode::fromLoose('OWN_FLEET'))->toBe(HaulageMode::OwnFleet)
        ->and(HaulageMode::fromLoose('third_party'))->toBe(HaulageMode::ThirdParty)
        ->and(HaulageMode::tryFromLoose('nope'))->toBeNull()
        ->and(LegStatus::fromLoose('IN_TRANSIT'))->toBe(LegStatus::InTransit)
        ->and(LegStatus::fromLoose('Closed'))->toBe(LegStatus::Closed)
        ->and(LegStatus::Closed->isClosed())->toBeTrue();
});
