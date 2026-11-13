<?php

declare(strict_types=1);

use Logistics\Fleet\Application\ReadModel\VehicleView;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleId;

/**
 * Proyección de lectura del vehículo: primitivos para listados/API.
 */
it('proyecta un vehículo disponible y apto para despachar', function (): void {
    $vehicle = Vehicle::register(
        VehicleId::generate(),
        PlateNumber::of('1234-ABC'),
        Capacity::of(3500, 18.0),
        'node-cd-scz',
        [DocumentExpiry::of('soat', new DateTimeImmutable('2027-01-01'))],
    );

    $data = VehicleView::fromDomain($vehicle)->toArray();

    expect($data['id'])->toBe($vehicle->id()->toString())
        ->and($data['plate'])->toBe('1234-ABC')
        ->and($data['capacity_kg'])->toBe(3500)
        ->and($data['capacity_m3'])->toBe(18.0)
        ->and($data['home_base_node_id'])->toBe('node-cd-scz')
        ->and($data['status'])->toBe('available')
        ->and($data['documents'])->toBe([['type' => 'soat', 'expires_on' => '2027-01-01']])
        ->and($data['fit_to_dispatch'])->toBeTrue()
        ->and($data['unfit_since'])->toBeNull()
        ->and($data['unfit_reason'])->toBeNull()
        ->and($data['unfit_items'])->toBe([]);
});
