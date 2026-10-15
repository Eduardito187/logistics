<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Seeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\DepartureFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\ExpenseFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\VehicleProfileFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;

/**
 * Seeder propio del contexto FleetOps con datos de demo realistas (combustible, mantenimiento, salidas,
 * perfil técnico y gastos de la flota propia en Bolivia).
 *
 * Siembra directamente la proyección de lectura (records Eloquent) a través de las fábricas: es la vía
 * estándar de demo y no depende del outbox ni del CommandBus. NO se registra en el DatabaseSeeder raíz
 * (lo hace el orquestador); puede correrse con `php artisan db:seed --class=...`.
 *
 * Importante (memoria seed-data-cross-refs): los `vehicle_id` NO se inventan. Se LEEN de `fleet_vehicles`
 * para que las relaciones del admin resuelvan la placa del vehículo; si no hay vehículos, se siembran solo
 * los precios de combustible (dato transversal, sin referencias cruzadas) para evitar registros huérfanos.
 */
final class FleetOpsDemoSeeder extends Seeder
{
    /**
     * Marca de los precios de combustible de demo: sirve de guardia de idempotencia.
     */
    private const DEMO_SOURCE = 'ANH-demo';

    /**
     * Precios vigentes de combustible en Bolivia (regulados ANH), en centavos de boliviano por litro.
     * GNV por metro cúbico. Son las referencias que usan el cálculo de consumo y el TCO.
     *
     * @var list<array{0: FuelType, 1: int}>
     */
    private const FUEL_PRICES = [
        [FuelType::Diesel, 372],           // 3.72 Bs/l
        [FuelType::GasolineSpecial, 479],  // 4.79 Bs/l
        [FuelType::GasolinePremium, 575],  // 5.75 Bs/l (referencial)
        [FuelType::Gnv, 166],              // 1.66 Bs/m³
    ];

    public function run(): void
    {
        // Idempotente: si los precios de demo ya están sembrados, no se vuelve a insertar nada.
        if (FuelPriceRecord::query()->where('source', self::DEMO_SOURCE)->exists()) {
            return;
        }

        // Precios de combustible vigentes (dato transversal; se siembra siempre, aun sin vehículos).
        foreach (self::FUEL_PRICES as [$fuelType, $cents]) {
            FuelPriceFactory::new()
                ->forFuelType($fuelType->value)
                ->priced($cents)
                ->effectiveFrom('2026-01-01')
                ->create(['source' => self::DEMO_SOURCE]);
        }

        // Vehículos REALES de la flota (ids de `fleet_vehicles`): como máximo tres para la demo.
        /** @var list<string> $vehicleIds */
        $vehicleIds = DB::table('fleet_vehicles')
            ->orderBy('plate')
            ->limit(3)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        // Sin vehículos: solo quedan los precios (evita huérfanos con `vehicle_id` inexistente).
        if ($vehicleIds === []) {
            return;
        }

        foreach ($vehicleIds as $index => $vehicleId) {
            $this->seedForVehicle($vehicleId, $index);
        }
    }

    /**
     * Siembra el set de demo de un vehículo real: perfil técnico, cargas, un plan + una orden completada,
     * una salida con carga y dos gastos.
     */
    private function seedForVehicle(string $vehicleId, int $index): void
    {
        // Odómetro base distinto por vehículo para dar variedad al consumo y a los planes por km.
        $baseKm = 80_000 + ($index * 25_000);

        // Perfil técnico (1:1 con el vehículo de fleet).
        VehicleProfileFactory::new()->create([
            'vehicle_id' => $vehicleId,
            'brand' => ['Volvo', 'Scania', 'Isuzu'][$index % 3],
            'model' => ['FH16', 'R450', 'NQR'][$index % 3],
            'year' => 2020 + ($index % 5),
            'fuel_type' => FuelType::Diesel->value,
            'tank_liters' => 300,
            'vin' => sprintf('DEMOVIN%09d', $index),
            'color' => ['White', 'Red', 'Blue'][$index % 3],
            'current_odometer_km' => $baseKm + 2_000,
        ]);

        // Tres cargas de diésel a tanque lleno, con odómetro creciente (para medir consumo entre llenados).
        $litersSeq = [55.0, 60.0, 58.0];
        foreach ($litersSeq as $i => $liters) {
            $odometer = $baseKm + ($i * 900);
            RefuelFactory::new()->forVehicle($vehicleId)->create([
                'driver_id' => null,
                'fuel_type' => FuelType::Diesel->value,
                'liters' => $liters,
                'price_per_liter_cents' => 372,
                'currency' => 'BOB',
                'total_cost_cents' => (int) round($liters * 372),
                'odometer_km' => $odometer,
                'station' => 'YPFB',
                'full_tank' => true,
                'refueled_at' => sprintf('2026-10-%02d 07:30:00', 2 + ($i * 4)),
                'note' => null,
            ]);
        }

        // Plan de mantenimiento preventivo por km (cambio de aceite cada 10 000 km).
        MaintenancePlanFactory::new()->forVehicle($vehicleId)->ofKind(ServiceKind::OilChange)->create([
            'interval_km' => 10_000,
            'interval_days' => 180,
            'last_done_km' => $baseKm,
            'last_done_at' => '2026-09-15 09:00:00',
            'next_due_km' => $baseKm + 10_000,
            'next_due_at' => '2027-03-14 09:00:00',
            'active' => true,
        ]);

        // Orden de mantenimiento completada con sus ítems y el costo total ya calculado.
        $items = [
            ['description' => 'Oil change', 'qty' => 1, 'part_cost_cents' => 9_000, 'labor_cost_cents' => 4_000],
            ['description' => 'Oil filter', 'qty' => 1, 'part_cost_cents' => 2_500, 'labor_cost_cents' => 1_500],
        ];
        // (9000 + 4000) + (2500 + 1500) = 17000.
        MaintenanceOrderFactory::new()->forVehicle($vehicleId)->completed(17_000, $items)->create([
            'type' => MaintenanceType::Preventive->value,
            'odometer_km' => $baseKm,
            'workshop' => 'Taller Central',
            'currency' => 'BOB',
            'scheduled_at' => '2026-09-14 08:00:00',
            'started_at' => '2026-09-15 08:30:00',
            'completed_at' => '2026-09-15 12:00:00',
        ]);

        // Salida ya retornada, con carga declarada y km recorridos.
        DepartureFactory::new()->forVehicle($vehicleId)->returned(420)->create([
            'driver_id' => null,
            'helper_ids' => [],
            'destination' => ['Cochabamba', 'Oruro', 'Santa Cruz'][$index % 3],
            'route_ref' => sprintf('RT-%03d', $index + 1),
            'load_description' => 'Línea blanca y electrónica',
            'load_weight_kg' => 1_250.0,
            'load_items' => [['name' => 'Refrigerator', 'qty' => 4], ['name' => 'TV', 'qty' => 6]],
            'odometer_out_km' => $baseKm + 2_000,
            'departed_at' => '2026-10-08 06:00:00',
            'note' => null,
        ]);

        // Dos gastos de flota (peaje y multa) para alimentar el TCO.
        ExpenseFactory::new()->forVehicle($vehicleId)->ofCategory(ExpenseCategory::Toll->value)->priced(5_000)->create([
            'description' => 'Toll La Paz - Oruro',
            'incurred_at' => '2026-10-08 07:15:00',
            'note' => null,
        ]);
        ExpenseFactory::new()->forVehicle($vehicleId)->ofCategory(ExpenseCategory::Fine->value)->priced(12_000)->create([
            'description' => 'Speeding fine',
            'incurred_at' => '2026-10-09 15:40:00',
            'note' => null,
        ]);
    }
}
