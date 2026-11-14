<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Repositorio Eloquent de seguimiento: búsqueda por id y envíos por delante en la ruta (excluye los
 * terminales y los de posición igual o anterior). Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function etrRepo(): TrackingRepository
{
    return app(TrackingRepository::class);
}

it('encuentra un seguimiento por su id', function (): void {
    $record = TrackingRecord::factory()->onTheWay()->create();

    $tracking = etrRepo()->findById(TrackingId::fromString($record->id));

    expect($tracking)->not->toBeNull()
        ->and($tracking->id()->toString())->toBe($record->id)
        ->and($tracking->shipmentId())->toBe($record->shipment_id);
});

it('devuelve null si el id no existe', function (): void {
    expect(etrRepo()->findById(TrackingId::generate()))->toBeNull();
});

it('lista los envíos por delante en la ruta, sin terminales ni posiciones anteriores', function (): void {
    $route = Uuid::generate()->value;

    $ahead1 = TrackingRecord::factory()->withStatus(VisibleStatus::OnTheWay)->create([
        'route_id' => $route,
        'route_position' => 4,
    ]);
    $ahead2 = TrackingRecord::factory()->withStatus(VisibleStatus::Loaded)->create([
        'route_id' => $route,
        'route_position' => 6,
    ]);
    // Posición igual o anterior a la del cliente: no van por delante.
    TrackingRecord::factory()->withStatus(VisibleStatus::OnTheWay)->create([
        'route_id' => $route,
        'route_position' => 2,
    ]);
    // Terminal: la ruta ya no lo mueve.
    TrackingRecord::factory()->withStatus(VisibleStatus::Returned)->create([
        'route_id' => $route,
        'route_position' => 8,
    ]);
    // Otra ruta: no cuenta.
    TrackingRecord::factory()->withStatus(VisibleStatus::OnTheWay)->create([
        'route_id' => Uuid::generate()->value,
        'route_position' => 5,
    ]);

    $ahead = etrRepo()->stopsAheadOnRoute($route, 3);

    $ids = array_map(static fn ($tracking): string => $tracking->id()->toString(), $ahead);
    expect($ids)->toBe([$ahead1->id, $ahead2->id]);
});
