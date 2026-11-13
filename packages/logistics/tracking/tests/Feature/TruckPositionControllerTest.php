<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Contrato HTTP de la ingesta de posición GPS del camión: valida forma/rango de los primitivos (422) y
 * acepta la escritura de alta frecuencia con 202. Se omite el middleware de puerta. Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('acepta una posición válida y la proyecta sobre el seguimiento del envío', function (): void {
    $shipment = Uuid::generate()->value;
    TrackingRecord::factory()->onTheWay()->create([
        'shipment_id' => $shipment,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    $this->postJson("/api/v1/app/tracking/shipments/{$shipment}/position", [
        'lat' => -16.5,
        'lng' => -68.15,
        'reported_at' => '2026-11-10T12:00:00+00:00',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.shipment_id', $shipment)
        ->assertJsonPath('data.accepted', true);

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect((float) $record->last_lat)->toBe(-16.5)
        ->and((float) $record->last_lng)->toBe(-68.15);
});

it('acepta la posición aunque todavía no exista seguimiento (proyección tolerante)', function (): void {
    $shipment = Uuid::generate()->value;

    $this->postJson("/api/v1/app/tracking/shipments/{$shipment}/position", [
        'lat' => -16.5,
        'lng' => -68.15,
        'reported_at' => '2026-11-10T12:00:00+00:00',
    ])->assertStatus(202);
});

it('rechaza con 422 una coordenada fuera de rango', function (): void {
    $shipment = Uuid::generate()->value;

    $this->postJson("/api/v1/app/tracking/shipments/{$shipment}/position", [
        'lat' => 120,
        'lng' => -68.15,
        'reported_at' => '2026-11-10T12:00:00+00:00',
    ])->assertStatus(422);
});

it('rechaza con 422 un reported_at que no es fecha', function (): void {
    $shipment = Uuid::generate()->value;

    $this->postJson("/api/v1/app/tracking/shipments/{$shipment}/position", [
        'lat' => -16.5,
        'lng' => -68.15,
        'reported_at' => 'no-es-fecha',
    ])->assertStatus(422);
});
