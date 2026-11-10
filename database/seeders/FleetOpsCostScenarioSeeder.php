<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\FleetOps\Application\Command\CompleteMaintenance\CompleteMaintenance;
use Logistics\FleetOps\Application\Command\RecordExpense\RecordExpense;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Application\Command\RecordRefuel\RecordRefuel;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Application\Command\StartMaintenance\StartMaintenance;
use Logistics\Shared\Application\Command\CommandBus;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Siembra costos operativos (fleetops) del camión de reparto del escenario de prueba (chofer@dismac.test),
 * para que la pantalla "Costos (últimos 30 días)" de la app Flutter muestre datos reales de punta a punta:
 * combustible, mantenimiento, gastos varios, lecturas de odómetro y tramos de telemetría (para el km del
 * costo por kilómetro). SOLO entornos locales/dev: en cualquier otro entorno no hace nada.
 *
 * Uso (dentro del contenedor):
 *   docker compose exec -T app php artisan db:seed --class="Database\Seeders\FleetOpsCostScenarioSeeder"
 *
 * Todo cae dentro de los últimos 30 días (zona America/La_Paz) respecto a "ahora". Recorre los casos de
 * uso del dominio (CommandBus) para combustible, odómetro, gastos y mantenimiento; los tramos de
 * telemetría se insertan como lectura read-only del monorepo (misma estrategia que el resumen de costos).
 *
 * Idempotente y aditivo: cada bloque se guarda con la marca de escenario ESCENARIO y se omite si ya existe
 * (combustible/odómetro/gastos por marca en `note`; mantenimiento por `workshop`; telemetría por id
 * determinístico). Correrlo dos veces no duplica nada. NUNCA borra ni trunca.
 */
final class FleetOpsCostScenarioSeeder extends Seeder
{
    private const string TIMEZONE = 'America/La_Paz';

    /** Camión de reparto del escenario (TodayScenarioSeeder, base CD Santa Cruz). */
    private const string ROUTE_VEHICLE_PLATE = '904U10';

    /** Marca de escenario para la guardia de idempotencia (en `note`/`workshop`/`description`). */
    private const string MARKER = 'escenario-costos';

    private const string CURRENCY = 'BOB';

    public function __construct(
        private readonly CommandBus $bus,
    ) {}

    public function run(): void
    {
        if (!app()->environment(['local', 'development', 'dev'])) {
            $this->command?->warn('FleetOpsCostScenarioSeeder solo corre en entornos locales/dev; no se hizo nada.');

            return;
        }

        $tz = new DateTimeZone(self::TIMEZONE);
        $now = new DateTimeImmutable('now', $tz);

        $vehicle = VehicleModel::query()->where('plate', self::ROUTE_VEHICLE_PLATE)->first();
        if ($vehicle === null) {
            throw new RuntimeException('Falta el vehículo '.self::ROUTE_VEHICLE_PLATE.' (correr FleetSeeder/TodayScenarioSeeder).');
        }
        $vehicleId = (string) $vehicle->id;

        // Conductor ligado al vehículo (para firmar las cargas de combustible y los tramos de telemetría).
        $driverId = $this->resolveDriverId($vehicleId);

        $this->seedRefuels($vehicleId, $driverId, $now);
        $this->seedOdometer($vehicleId, $now);
        $this->seedExpenses($vehicleId, $now);
        $this->seedMaintenance($vehicleId, $now);
        $this->seedTelemetryTrips($vehicleId, $driverId, $now);

        $this->command?->info(sprintf(
            'Costos de flota sembrados para el camión %s (id %s) en los últimos 30 días.',
            self::ROUTE_VEHICLE_PLATE,
            $vehicleId,
        ));
    }

    /**
     * Id del conductor activo habilitado para el camión (o cualquiera activo como respaldo). Puede ser null
     * si fleet no está sembrado; las cargas/tramos aceptan driverId nulo.
     */
    private function resolveDriverId(string $vehicleId): ?string
    {
        $driverId = DB::table('fleet_drivers')
            ->where('active', true)
            ->whereJsonContains('enabled_vehicle_ids', $vehicleId)
            ->orderBy('id')
            ->value('id');

        return $driverId !== null ? (string) $driverId : null;
    }

    /**
     * Cargas de combustible (diésel) a lo largo de la ventana, con tanque lleno y odómetro creciente (base
     * del análisis de consumo entre llenados). Guardia por marca en `note`.
     */
    private function seedRefuels(string $vehicleId, ?string $driverId, DateTimeImmutable $now): void
    {
        if (DB::table('fleetops_refuels')->where('vehicle_id', $vehicleId)->where('note', self::MARKER)->exists()) {
            return;
        }

        // [días atrás, litros, odómetro km]. Precio diésel subvencionado Bolivia: 3,72 BOB/L (372 centavos).
        $refuels = [
            [28, 180.0, 120000],
            [21, 172.5, 120850],
            [14, 185.0, 121700],
            [7, 168.0, 122540],
        ];

        foreach ($refuels as [$daysAgo, $liters, $odometerKm]) {
            $this->bus->dispatch(new RecordRefuel(
                vehicleId: $vehicleId,
                driverId: $driverId,
                fuelType: 'diesel',
                liters: $liters,
                pricePerLiterCents: 372,
                currency: self::CURRENCY,
                odometerKm: $odometerKm,
                station: 'YPFB Santa Cruz',
                fullTank: true,
                refueledAt: $now->modify("-{$daysAgo} days")->setTime(7, 30)->format(DateTimeInterface::ATOM),
                note: self::MARKER,
            ));
        }
    }

    /**
     * Lecturas de odómetro que cubren la ventana (append-only; alimentan odómetro actual, consumo y
     * utilización). Guardia por marca en `note`.
     */
    private function seedOdometer(string $vehicleId, DateTimeImmutable $now): void
    {
        if (DB::table('fleetops_odometer_readings')->where('vehicle_id', $vehicleId)->where('note', self::MARKER)->exists()) {
            return;
        }

        // [días atrás, km].
        $readings = [
            [28, 120000],
            [21, 120850],
            [14, 121700],
            [7, 122540],
            [1, 123280],
        ];

        foreach ($readings as [$daysAgo, $km]) {
            $this->bus->dispatch(new RecordOdometer(
                vehicleId: $vehicleId,
                km: $km,
                source: 'manual',
                readAt: $now->modify("-{$daysAgo} days")->setTime(6, 0)->format(DateTimeInterface::ATOM),
                note: self::MARKER,
            ));
        }
    }

    /**
     * Gastos varios del camión (peaje, lavado, llantas). Guardia por marca en `note`.
     */
    private function seedExpenses(string $vehicleId, DateTimeImmutable $now): void
    {
        if (DB::table('fleetops_expenses')->where('vehicle_id', $vehicleId)->where('note', self::MARKER)->exists()) {
            return;
        }

        // [días atrás, categoría, centavos, descripción].
        $expenses = [
            [20, 'toll', 5000, 'Peaje ruta al interior'],
            [12, 'wash', 8000, 'Lavado y engrase'],
            [5, 'tires', 120000, 'Cambio de dos llantas traseras'],
        ];

        foreach ($expenses as [$daysAgo, $category, $cents, $description]) {
            $this->bus->dispatch(new RecordExpense(
                vehicleId: $vehicleId,
                category: $category,
                amountCents: $cents,
                currency: self::CURRENCY,
                incurredAt: $now->modify("-{$daysAgo} days")->setTime(10, 0)->format(DateTimeInterface::ATOM),
                description: $description,
                note: self::MARKER,
            ));
        }
    }

    /**
     * Una orden de mantenimiento preventivo agendada, iniciada y COMPLETADA (su cierre cae en la ventana,
     * por eso suma al costo del período). Guardia por `workshop` marcado.
     */
    private function seedMaintenance(string $vehicleId, DateTimeImmutable $now): void
    {
        $workshop = 'Taller Central ('.self::MARKER.')';

        if (DB::table('fleetops_maintenance_orders')->where('vehicle_id', $vehicleId)->where('workshop', $workshop)->exists()) {
            return;
        }

        $this->bus->dispatch(new ScheduleMaintenance(
            vehicleId: $vehicleId,
            type: 'preventive',
            odometerKm: 121700,
            workshop: $workshop,
            scheduledAt: $now->modify('-10 days')->setTime(8, 0)->format(DateTimeInterface::ATOM),
            note: 'Service de 120.000 km',
        ));

        $orderId = DB::table('fleetops_maintenance_orders')
            ->where('vehicle_id', $vehicleId)
            ->where('workshop', $workshop)
            ->orderByDesc('created_at')
            ->value('id');
        if ($orderId === null) {
            throw new RuntimeException('No se pudo agendar la orden de mantenimiento del escenario de costos.');
        }
        $orderId = (string) $orderId;

        $this->bus->dispatch(new StartMaintenance($orderId));

        // Ítems (repuestos + mano de obra), en centavos. El agregado suma los lineTotal() y fija el cierre
        // en "ahora" (dentro de la ventana de 30 días).
        $this->bus->dispatch(new CompleteMaintenance(
            orderId: $orderId,
            items: [
                ['description' => 'Cambio de aceite de motor', 'qty' => 1, 'part_cost_cents' => 35000, 'labor_cost_cents' => 15000],
                ['description' => 'Filtro de aceite y de combustible', 'qty' => 1, 'part_cost_cents' => 12000, 'labor_cost_cents' => 8000],
                ['description' => 'Revisión general de frenos', 'qty' => 1, 'part_cost_cents' => 0, 'labor_cost_cents' => 18000],
            ],
            currency: self::CURRENCY,
        ));
    }

    /**
     * Tramos de telemetría completados a lo largo de la ventana: aportan los kilómetros con que el resumen
     * de costos calcula el costo por km (lee `telemetry_trips.distance_m`, igual que GetVehicleCostSummary).
     * Inserción directa con id determinístico (uuid5) para que sea idempotente sin tocar el contexto de
     * telemetría. Guardia por existencia del id.
     */
    private function seedTelemetryTrips(string $vehicleId, ?string $driverId, DateTimeImmutable $now): void
    {
        // Barrio del CD Santa Cruz → destino de reparto (coordenadas reales de la zona).
        $startLat = -17.7490;
        $startLng = -63.1540;
        $endLat = -17.7898;
        $endLng = -63.1868;

        // [días atrás, metros, segundos de duración].
        $trips = [
            [27, 58000, 7200],
            [24, 61500, 7500],
            [20, 55000, 6900],
            [17, 63000, 7800],
            [13, 59500, 7300],
            [9, 57000, 7000],
            [4, 62000, 7600],
        ];

        $stamp = $now->format('Y-m-d H:i:s');
        foreach ($trips as [$daysAgo, $meters, $seconds]) {
            $startedAt = $now->modify("-{$daysAgo} days")->setTime(8, 0);
            $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'fleetops-cost-trip|'.$vehicleId.'|'.$daysAgo);

            // updateOrInsert: no duplica si ya está sembrado; aditivo respecto a la telemetría real.
            DB::table('telemetry_trips')->updateOrInsert(
                ['id' => $id],
                [
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'route_id' => null,
                    'status' => 'completed',
                    'started_at' => $startedAt->format(DateTimeInterface::ATOM),
                    'ended_at' => $startedAt->modify("+{$seconds} seconds")->format(DateTimeInterface::ATOM),
                    'start_lat' => $startLat,
                    'start_lng' => $startLng,
                    'end_lat' => $endLat,
                    'end_lng' => $endLng,
                    'distance_m' => $meters,
                    'duration_s' => $seconds,
                    'moving_s' => $seconds,
                    'idle_s' => 0,
                    'max_speed_kph' => 62.0,
                    'ping_count' => 40,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ],
            );
        }
    }
}
