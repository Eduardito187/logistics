<?php

declare(strict_types=1);

use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;

/**
 * Test de contrato HTTP de la promesa: la respuesta debe traer zone_name (nombre legible de la zona de
 * cobertura, coverage_zones.name) junto al UUID crudo zone_id, resuelto de solo lectura en el QueryHandler.
 *
 * Se omite el middleware de puerta (channels) para ejercitar solo el contrato del payload.
 * Corre contra logistics_test (RefreshDatabase).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('expone zone_name resuelto desde la zona de cobertura', function (): void {
    $zone = ZoneRecord::factory()->create(['name' => 'Centro La Paz']);

    $promise = PromiseRecord::factory()->create(['zone_id' => $zone->id]);

    $this->getJson("/api/v1/channels/promises/{$promise->id}")
        ->assertOk()
        ->assertJsonPath('data.zone_id', $zone->id)
        ->assertJsonPath('data.zone_name', 'Centro La Paz');
});

it('deja zone_name null cuando la zona no existe', function (): void {
    $promise = PromiseRecord::factory()->create([
        'zone_id' => '00000000-0000-7000-8000-000000000009',
    ]);

    $this->getJson("/api/v1/channels/promises/{$promise->id}")
        ->assertOk()
        ->assertJsonPath('data.zone_name', null);
});
