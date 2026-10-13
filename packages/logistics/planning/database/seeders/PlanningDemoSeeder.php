<?php

declare(strict_types=1);

namespace Logistics\Planning\Database\Seeders;

use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Seeder de demo del contexto Planificación: arma planes de reparto realistas para HOY recorriendo el
 * camino real del dominio (borrador → propuesta de ruta → plan de carga → publicación), por lo que la
 * secuencia, la ETA y el carril salen de las mismas reglas que usa la API, no de datos inventados.
 *
 * El dominio solo conoce vehículos y envíos por uuid (planificación no depende de flota ni envíos),
 * pero el SEEDER —que vive en Infrastructure— lee los ids reales ya sembrados (fleet_vehicles y
 * shipments) para que las paradas y el vehículo del plan no queden huérfanos en el admin, igual que
 * hace CapacitySeeder con los nodos. Si esas tablas están vacías, cae en uuids generados para no
 * acoplar el orden. Pensado para ejecutarse desde el DatabaseSeeder del proyecto o con
 * `php artisan db:seed --class=...`.
 */
final class PlanningDemoSeeder extends Seeder
{
    /**
     * Camiones de demo con sus paradas (coordenadas reales de la ciudad de La Paz, Bolivia). El último
     * queda en borrador a propósito, para mostrar el estado inicial del ciclo de vida.
     *
     * @var list<array{stops: list<array{lat: float, lng: float}>, publish: bool, draft?: bool}>
     */
    private const DEMO_TRUCKS = [
        // Camión 1: reparto completo publicado (listo para la ejecución).
        [
            'stops' => [
                ['lat' => -16.4955, 'lng' => -68.1336], // Centro
                ['lat' => -16.5089, 'lng' => -68.1193], // Miraflores
                ['lat' => -16.5235, 'lng' => -68.0869], // Villa Fátima
                ['lat' => -16.5406, 'lng' => -68.0702], // Zona Sur (Obrajes)
            ],
            'publish' => true,
        ],
        // Camión 2: propuesta generada, pendiente de confirmación humana.
        [
            'stops' => [
                ['lat' => -16.4639, 'lng' => -68.1730], // El Alto (Ceja)
                ['lat' => -16.5028, 'lng' => -68.1500], // Sopocachi
                ['lat' => -16.5365, 'lng' => -68.0847], // Calacoto
            ],
            'publish' => false,
        ],
        // Camión 3: borrador recién armado, todavía sin correr el motor de rutas.
        [
            'stops' => [
                ['lat' => -16.4900, 'lng' => -68.1400],
                ['lat' => -16.5100, 'lng' => -68.1250],
            ],
            'publish' => false,
            'draft' => true,
        ],
    ];

    public function run(): void
    {
        // Idempotente: si los planes de demo ya están sembrados, no se vuelve a crear ninguno (un
        // segundo run rompería la unicidad planning_plans(plan_date, vehicle_id)). El contexto
        // Planning es dueño exclusivo de planning_plans, así que basta con que la tabla tenga filas.
        if (PlanRecord::query()->exists()) {
            return;
        }

        /** @var PlanRepository $plans */
        $plans = app(PlanRepository::class);
        /** @var RouteOptimizer $optimizer */
        $optimizer = app(RouteOptimizer::class);

        $date = PlanDate::of(new DateTimeImmutable('today'));
        $departAt = new DateTimeImmutable('today 07:30:00');

        // IDs reales de flota y envíos ya sembrados (upstream). Cada plan toma un vehículo distinto
        // (la tabla exige un plan por día+vehículo) y cada parada un envío real.
        $vehicleIds = $this->vehicleIds();
        $shipmentPool = $this->shipmentIds();

        foreach (self::DEMO_TRUCKS as $truckIndex => $truck) {
            $plan = DeliveryPlan::startDraft(
                $plans->nextIdentity(),
                $date,
                $vehicleIds[$truckIndex] ?? Uuid::generate()->value,
            );

            // Cada parada referencia un envío real distinto dentro del plan; si no alcanzan los envíos
            // reales, se completa con uuids generados para no violar la unicidad (plan_id, shipment_id).
            $shipmentIds = $this->shipmentIdsForStops(count($truck['stops']), $shipmentPool);

            foreach ($shipmentIds as $shipmentId) {
                $plan->assignShipment(PlannedStopId::generate(), $shipmentId);
            }

            // Un borrador se guarda tal cual: sin secuencia definitiva, ETA ni carga.
            if (($truck['draft'] ?? false) === true) {
                $plans->save($plan);

                continue;
            }

            $routeStops = [];
            foreach ($truck['stops'] as $index => $coord) {
                $routeStops[] = RouteStop::of($shipmentIds[$index], $coord['lat'], $coord['lng']);
            }

            $optimized = $optimizer->optimize($departAt, $routeStops);

            $orderedShipmentIds = [];
            /** @var array<string, Eta> $etas */
            $etas = [];
            foreach ($optimized as $stop) {
                $orderedShipmentIds[] = $stop->shipmentId;
                $etas[$stop->shipmentId] = $stop->eta;
            }

            $plan->proposeRoute($orderedShipmentIds, $etas);
            $plan->recomputeLoadPlan();

            if ($truck['publish'] === true) {
                $plan->publish();
            }

            $plans->save($plan);
        }
    }

    /**
     * IDs reales de vehículos (ordenados por placa) ya sembrados por Fleet.
     *
     * @return list<string>
     */
    private function vehicleIds(): array
    {
        /** @var list<string> $ids */
        $ids = VehicleModel::query()->orderBy('plate')->pluck('id')->all();

        return $ids;
    }

    /**
     * IDs reales de envíos (ordenados por external_id) ya sembrados por Shipments.
     *
     * @return list<string>
     */
    private function shipmentIds(): array
    {
        /** @var list<string> $ids */
        $ids = ShipmentRecord::query()->orderBy('external_id')->pluck('id')->all();

        return $ids;
    }

    /**
     * Devuelve $count ids de envío DISTINTOS para las paradas de un plan: primero los reales del pool y,
     * si no alcanzan, completa con uuids generados (así nunca se viola la unicidad por plan).
     *
     * @param  list<string> $pool
     * @return list<string>
     */
    private function shipmentIdsForStops(int $count, array $pool): array
    {
        $ids = array_slice($pool, 0, $count);

        while (count($ids) < $count) {
            $ids[] = Uuid::generate()->value;
        }

        return $ids;
    }
}
