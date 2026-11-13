<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\UnitTrackingRecord;

/**
 * Repositorio Eloquent del seguimiento per-bulto: búsqueda por unit_id y listado por envío (ordenado por
 * unit_id). Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function eutrRepo(): UnitTrackingRepository
{
    return app(UnitTrackingRepository::class);
}

it('encuentra el seguimiento de un bulto por su unit_id', function (): void {
    $record = UnitTrackingRecord::factory()->withStatus(UnitStatus::Loaded)->create();

    $unit = eutrRepo()->findByUnitId($record->unit_id);

    expect($unit)->not->toBeNull()
        ->and($unit->id()->toString())->toBe($record->id);
});

it('devuelve null si el unit_id no existe', function (): void {
    expect(eutrRepo()->findByUnitId(Uuid::generate()->value))->toBeNull();
});

it('lista los bultos de un envío', function (): void {
    $shipment = Uuid::generate()->value;
    UnitTrackingRecord::factory()->forShipment($shipment)->count(2)->create();
    UnitTrackingRecord::factory()->create();

    $units = eutrRepo()->listByShipmentId($shipment);

    expect($units)->toHaveCount(2);
});
