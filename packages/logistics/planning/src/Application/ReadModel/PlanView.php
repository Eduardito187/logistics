<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use DateTimeInterface;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;

/**
 * Modelo de lectura del plan de reparto: forma plana en primitivos para APIs, listados y paneles.
 *
 * Se arma desde el agregado con fromPlan(); las consultas nunca exponen el objeto de dominio.
 */
final readonly class PlanView
{
    /**
     * @param list<PlannedStopView>     $stops
     * @param array<string, mixed>|null $routeGeometry geometría de la ruta por calles (GeoJSON + totales)
     */
    public function __construct(
        public string $id,
        public string $planDate,
        public string $vehicleId,
        public string $status,
        public array $stops,
        public ?array $routeGeometry = null,
    ) {}

    public static function fromPlan(DeliveryPlan $plan): self
    {
        $total = count($plan->stops());

        $stops = [];
        foreach ($plan->stops() as $stop) {
            $stops[] = self::stopView($stop, $total);
        }

        return new self(
            $plan->id()->toString(),
            $plan->date()->toString(),
            $plan->vehicleId(),
            $plan->status()->value,
            $stops,
            $plan->geometry()?->toArray(),
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
            'status' => $this->status,
            'stops' => array_map(static fn (PlannedStopView $stop): array => $stop->toArray(), $this->stops),
            // Geometría server-side para que el mapa la pinte sin depender del navegador (null si aún no se calculó).
            'route_geometry' => $this->routeGeometry,
        ];
    }

    private static function stopView(PlannedStop $stop, int $total): PlannedStopView
    {
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
            $stop->orderReference(),
            $stop->customerName(),
            $stop->address(),
        );
    }
}
