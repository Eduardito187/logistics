<?php

declare(strict_types=1);

use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\TariffFactory;

/**
 * Tests de contrato HTTP: las respuestas de tarifas, liquidaciones y asignación deben traer el
 * nombre legible del transportista (carrier_name) junto al UUID crudo (carrier_id).
 *
 * Se omite el middleware de puerta (auth/device) para ejercitar solo el contrato del payload.
 * Corre contra logistics_test (RefreshDatabase).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('expone carrier_name en la tarifa de un corredor', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Transportes Andes SRL']);

    TariffFactory::new()
        ->forCarrier($carrier->id)
        ->forCorridor('CD-LPZ', 'CD-SCZ', 'cd_to_cd')
        ->forVehicleClass('trailer')
        ->create();

    $this->getJson("/api/v1/app/carriers/{$carrier->id}/tariff?corridor_from=CD-LPZ&corridor_to=CD-SCZ&corridor_type=cd_to_cd&vehicle_class=trailer")
        ->assertOk()
        ->assertJsonPath('data.carrier_id', $carrier->id)
        ->assertJsonPath('data.carrier_name', 'Transportes Andes SRL');
});

it('expone carrier_name en la tarifa más barata del corredor', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Logística del Sur']);

    TariffFactory::new()
        ->forCarrier($carrier->id)
        ->forCorridor('CD-LPZ', 'CD-CBB', 'cd_to_cd')
        ->forVehicleClass('trailer')
        ->priced(120_000)
        ->create();

    $this->getJson('/api/v1/app/carriers/tariffs/cheapest?corridor_from=CD-LPZ&corridor_to=CD-CBB&corridor_type=cd_to_cd')
        ->assertOk()
        ->assertJsonPath('data.carrier_id', $carrier->id)
        ->assertJsonPath('data.carrier_name', 'Logística del Sur');
});

it('expone carrier_name en la tarifa embebida de una asignación por volumen', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Flota Altiplano']);

    TariffFactory::new()
        ->forCarrier($carrier->id)
        ->forCorridor('CD-LPZ', 'CD-SCZ', 'cd_to_cd')
        ->forVehicleClass('trailer')
        ->priced(200_000)
        ->create();

    // Volumen >= 40 m³ ⇒ tráiler (CorridorAssignmentPolicy).
    $this->getJson('/api/v1/app/carriers/assignment?corridor_from=CD-LPZ&corridor_to=CD-SCZ&corridor_type=cd_to_cd&volume_m3=50')
        ->assertOk()
        ->assertJsonPath('data.tariff.carrier_id', $carrier->id)
        ->assertJsonPath('data.tariff.carrier_name', 'Flota Altiplano');
});

it('expone carrier_name en el listado y el detalle de liquidaciones', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Cargo Express Bolivia']);

    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    $this->getJson("/api/v1/app/carriers/settlements?carrier_id={$carrier->id}")
        ->assertOk()
        ->assertJsonPath('data.0.carrier_id', $carrier->id)
        ->assertJsonPath('data.0.carrier_name', 'Cargo Express Bolivia');

    $this->getJson("/api/v1/app/carriers/settlements/{$settlement->id}")
        ->assertOk()
        ->assertJsonPath('data.carrier_id', $carrier->id)
        ->assertJsonPath('data.carrier_name', 'Cargo Express Bolivia');
});
