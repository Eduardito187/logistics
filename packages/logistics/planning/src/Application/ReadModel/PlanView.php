<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use DateTimeInterface;
use DateTimeZone;
use Logistics\Planning\Application\Service\CustomerTimeWindow;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;

/**
 * Modelo de lectura del plan de reparto: forma plana en primitivos para APIs, listados y paneles.
 *
 * Se arma desde el agregado con fromPlan(); las consultas nunca exponen el objeto de dominio. Los datos
 * humanos —placa del camión, chofer del día, de dónde sale el camión y los datos legibles de cada parada
 * (pedido, cliente, dirección corta, modalidad, unidades, hora elegida por el cliente)— los resuelve EN
 * LOTE el PlanViewAssembler; quedan en null si no se conocen.
 *
 * `distanceKm` / `durationMinutes` son los totales del recorrido por calles ya calculado y guardado con el
 * plan (su geometría); null mientras el plan no la tenga.
 */
final readonly class PlanView
{
    /** Zona horaria con la que se ancla la hora elegida por el cliente cuando no se indica otra. */
    private const DEFAULT_TIMEZONE = 'America/La_Paz';

    /**
     * @param list<PlannedStopView>                $stops
     * @param array<string, mixed>|null            $routeGeometry geometría de la ruta por calles (GeoJSON + totales)
     * @param array{id: string, name: string}|null $originNode    nodo del que sale el camión
     */
    public function __construct(
        public string $id,
        public string $planDate,
        public string $vehicleId,
        public string $status,
        public array $stops,
        public ?array $routeGeometry = null,
        public ?string $plate = null,
        public ?string $driverName = null,
        public ?array $originNode = null,
        public ?float $distanceKm = null,
        public ?int $durationMinutes = null,
    ) {}

    /**
     * @param array<string, ShipmentPlanningFacts> $facts      datos legibles por id de envío (lote del assembler)
     * @param array{id: string, name: string}|null $originNode nodo del que sale el camión
     */
    public static function fromPlan(
        DeliveryPlan $plan,
        ?string $plate = null,
        ?string $driverName = null,
        array $facts = [],
        ?array $originNode = null,
        ?DateTimeZone $timezone = null,
    ): self {
        $total = count($plan->stops());
        $planDate = $plan->date()->toString();
        $timezone ??= new DateTimeZone(self::DEFAULT_TIMEZONE);

        $stops = [];
        foreach ($plan->stops() as $stop) {
            $stops[] = self::stopView($stop, $total, $planDate, $timezone, $facts[strtolower($stop->shipmentId())] ?? null);
        }

        $geometry = $plan->geometry();

        return new self(
            $plan->id()->toString(),
            $planDate,
            $plan->vehicleId(),
            $plan->status()->value,
            $stops,
            $geometry?->toArray(),
            $plate,
            $driverName,
            $originNode,
            $geometry === null ? null : round($geometry->distanceMeters / 1000.0, 1),
            $geometry === null ? null : (int) round($geometry->durationSeconds / 60.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'plan_date' => $this->planDate,
            'vehicle_id' => $this->vehicleId,
            // Datos humanos del camión y del chofer del día (null si no se conocen).
            'plate' => $this->plate,
            'driver_name' => $this->driverName,
            // De dónde sale el camión ({id, name}) y los totales del recorrido ya calculado (null si no hay).
            'origin_node' => $this->originNode,
            'distance_km' => $this->distanceKm,
            'duration_minutes' => $this->durationMinutes,
            'status' => $this->status,
            'stops' => array_map(static fn (PlannedStopView $stop): array => $stop->toArray(), $this->stops),
            // Geometría server-side para que el mapa la pinte sin depender del navegador (null si aún no se calculó).
            'route_geometry' => $this->routeGeometry,
        ];
    }

    private static function stopView(
        PlannedStop $stop,
        int $total,
        string $planDate,
        DateTimeZone $timezone,
        ?ShipmentPlanningFacts $facts,
    ): PlannedStopView {
        $eta = $stop->eta();
        $lane = $stop->lane();

        return new PlannedStopView(
            $stop->id()->toString(),
            $stop->shipmentId(),
            $stop->sequence()->value,
            $eta?->arrivalAt->format(DateTimeInterface::ATOM),
            $eta?->minutesFromDeparture,
            $lane?->label,
            // Orden de carga = inverso del de entrega: la última entrega carga primero.
            $total - $stop->sequence()->value + 1,
            // La foto guardada con la parada manda; si la parada nació sin ella, se completa con el envío.
            $stop->orderReference() ?? $facts?->orderReference,
            $stop->customerName() ?? $facts?->customerName,
            $stop->address() ?? $facts?->address,
            $facts?->shortAddress,
            $facts?->deliveryMode,
            $facts?->unitsTotal,
            $facts === null
                ? null
                : CustomerTimeWindow::on($planDate, $facts->windowStart, $facts->windowEnd, $timezone)?->toArray(),
        );
    }
}
