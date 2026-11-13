<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\Command\ActivateFuelPrice\ActivateFuelPrice;
use Logistics\FleetOps\Application\Command\DeactivateFuelPrice\DeactivateFuelPrice;
use Logistics\FleetOps\Application\Command\DeleteFuelPrice\DeleteFuelPrice;
use Logistics\FleetOps\Application\Command\UpdateFuelPrice\UpdateFuelPrice;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ciclo de vida de un precio de combustible por comandos contra PostgreSQL (logistics_test): activar,
 * desactivar, editar y eliminar. Cada mutación persiste y deja eventos en el outbox (fleetops.fuel_price).
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('activa un precio inactivo y deja el evento en el outbox', function (): void {
    $id = FuelPriceFactory::new()->create(['active' => false])->id;

    app(CommandBus::class)->dispatch(new ActivateFuelPrice($id));

    expect(FuelPriceRecord::query()->findOrFail($id)->active)->toBeTrue()
        ->and(DB::table('outbox_messages')->where('aggregate_type', 'fleetops.fuel_price')->count())->toBeGreaterThanOrEqual(1);
});

it('desactiva un precio activo', function (): void {
    $id = FuelPriceFactory::new()->create(['active' => true])->id;

    app(CommandBus::class)->dispatch(new DeactivateFuelPrice($id));

    expect(FuelPriceRecord::query()->findOrFail($id)->active)->toBeFalse();
});

it('edita precio, moneda y tipo de combustible', function (): void {
    $id = FuelPriceFactory::new()->forFuelType(FuelType::Diesel->value)->priced(372)->create()->id;

    app(CommandBus::class)->dispatch(new UpdateFuelPrice(
        id: $id,
        priceCents: 410,
        currency: 'BOB',
        effectiveFrom: '2026-11-01',
        source: 'manual',
        fuelType: FuelType::GasolineSpecial->value,
    ));

    $record = FuelPriceRecord::query()->findOrFail($id);
    expect((int) $record->price_cents)->toBe(410)
        ->and($record->fuel_type)->toBe(FuelType::GasolineSpecial->value);
});

it('elimina un precio del registro y deja el evento', function (): void {
    $id = FuelPriceFactory::new()->create()->id;

    app(CommandBus::class)->dispatch(new DeleteFuelPrice($id));

    expect(FuelPriceRecord::query()->find($id))->toBeNull();
});

it('falla al activar un precio inexistente', function (): void {
    expect(fn () => app(CommandBus::class)->dispatch(new ActivateFuelPrice('019299dd-0000-7000-8000-00000000e001')))
        ->toThrow(FleetOpsException::class, 'No existe el precio');
});
