<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPositionHandler;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Handler que proyecta la última posición del camión sobre el seguimiento del envío: solo aplica si hay
 * seguimiento, descarta lecturas más viejas que la guardada y valida el instante. Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function utpBus(): CommandBus
{
    return app(CommandBus::class);
}

it('guarda la posición cuando el seguimiento existe y la lectura es fresca', function (): void {
    $shipment = Uuid::generate()->value;
    TrackingRecord::factory()->onTheWay()->create([
        'shipment_id' => $shipment,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    utpBus()->dispatch(new UpdateTruckPosition(
        shipmentId: $shipment,
        latitude: -16.5,
        longitude: -68.15,
        reportedAt: '2026-11-10T12:00:00+00:00',
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect((float) $record->last_lat)->toBe(-16.5)
        ->and((float) $record->last_lng)->toBe(-68.15)
        ->and($record->last_position_at)->not->toBeNull();
});

it('guarda la cinemática GPS que vino en el reporte', function (): void {
    $shipment = Uuid::generate()->value;
    TrackingRecord::factory()->onTheWay()->create([
        'shipment_id' => $shipment,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    utpBus()->dispatch(new UpdateTruckPosition(
        shipmentId: $shipment,
        latitude: -16.5,
        longitude: -68.15,
        reportedAt: '2026-11-10T12:00:00+00:00',
        speedMps: 12.5,
        headingDeg: 90.0,
        accuracyM: 8.0,
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect((float) $record->last_speed_mps)->toBe(12.5)
        ->and((float) $record->last_heading_deg)->toBe(90.0)
        ->and((float) $record->last_accuracy_m)->toBe(8.0);
});

it('deriva velocidad y rumbo de dos posiciones seguidas cuando no vienen en el reporte', function (): void {
    $shipment = Uuid::generate()->value;
    TrackingRecord::factory()->onTheWay()->create([
        'shipment_id' => $shipment,
        'last_lat' => (string) -16.5,
        'last_lng' => (string) -68.15,
        'last_position_at' => '2026-11-10T12:00:00+00:00',
    ]);

    // ~1 km al este, 100 s después: sin speed/heading en el comando, la ingesta los deriva.
    utpBus()->dispatch(new UpdateTruckPosition(
        shipmentId: $shipment,
        latitude: -16.5,
        longitude: -68.1406,
        reportedAt: '2026-11-10T12:01:40+00:00',
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect((float) $record->last_speed_mps)->toBeGreaterThan(5.0)
        ->and((float) $record->last_heading_deg)->toBeGreaterThan(70.0)->toBeLessThan(110.0);
});

it('descarta una lectura más vieja que la última conocida (proyección monótona)', function (): void {
    $shipment = Uuid::generate()->value;
    TrackingRecord::factory()->onTheWay()->create([
        'shipment_id' => $shipment,
        'last_lat' => '-16.40',
        'last_lng' => '-68.00',
        'last_position_at' => '2026-11-10T12:00:00+00:00',
    ]);

    utpBus()->dispatch(new UpdateTruckPosition(
        shipmentId: $shipment,
        latitude: -16.5,
        longitude: -68.15,
        reportedAt: '2026-11-10T11:00:00+00:00',
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect((float) $record->last_lat)->toBe(-16.40)
        ->and((float) $record->last_lng)->toBe(-68.00);
});

it('no hace nada si no hay seguimiento para el envío', function (): void {
    $shipment = Uuid::generate()->value;

    utpBus()->dispatch(new UpdateTruckPosition(
        shipmentId: $shipment,
        latitude: -16.5,
        longitude: -68.15,
        reportedAt: '2026-11-10T12:00:00+00:00',
    ));

    expect(TrackingRecord::query()->where('shipment_id', $shipment)->exists())->toBeFalse();
});

it('rechaza un instante de reporte inválido', function (): void {
    $shipment = Uuid::generate()->value;
    TrackingRecord::factory()->onTheWay()->create(['shipment_id' => $shipment]);

    expect(fn () => utpBus()->dispatch(new UpdateTruckPosition(
        shipmentId: $shipment,
        latitude: -16.5,
        longitude: -68.15,
        reportedAt: 'no-es-una-fecha',
    )))->toThrow(TrackingException::class, 'instante del reporte');
});

it('rechaza un comando que no es UpdateTruckPosition', function (): void {
    $other = new class implements Command {};

    expect(fn () => app(UpdateTruckPositionHandler::class)->handle($other))
        ->toThrow(TrackingException::class, 'UpdateTruckPosition');
});
