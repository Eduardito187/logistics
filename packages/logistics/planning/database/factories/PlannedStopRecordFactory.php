<?php

declare(strict_types=1);

namespace Logistics\Planning\Database\Factories;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `planning_plan_stops`: la entrega de un envío en una posición de la secuencia.
 *
 * Por defecto nace sin ETA ni carril (como una parada recién asignada en borrador); scheduled() la
 * deja ya resuelta con ETA y carril como la dejaría la propuesta de ruta + el plan de carga.
 *
 * @extends Factory<PlannedStopRecord>
 */
final class PlannedStopRecordFactory extends Factory
{
    /**
     * Minutos de viaje asumidos entre paradas consecutivas (coherente con el stub del motor de rutas).
     */
    private const MINUTES_PER_LEG = 20;

    /**
     * Cantidad de bultos por carril de carga (coherente con DeliveryPlan::LANE_SIZE).
     */
    private const LANE_SIZE = 6;

    /**
     * @var class-string<PlannedStopRecord>
     */
    protected $model = PlannedStopRecord::class;

    public function definition(): array
    {
        return [
            'id' => PlannedStopId::generate()->toString(),
            'plan_id' => PlanRecordFactory::new(),
            'shipment_id' => Uuid::generate()->value,
            'sequence' => 1,
            'eta' => null,
            'eta_minutes' => null,
            'lane' => null,
        ];
    }

    /**
     * Parada ya resuelta: ETA derivada de la posición y carril de carga en orden inverso de entrega.
     */
    public function scheduled(int $sequence): self
    {
        $minutes = $sequence * self::MINUTES_PER_LEG;
        $arrivalAt = (new DateTimeImmutable('today 07:30:00'))->modify("+{$minutes} minutes");
        // El carril se calcula luego por el agregado; para demo basta una letra estable por secuencia.
        $lane = chr(ord('A') + intdiv($sequence - 1, self::LANE_SIZE));

        return $this->state(fn (): array => [
            'sequence' => $sequence,
            'eta' => $arrivalAt->format(DateTimeInterface::ATOM),
            'eta_minutes' => $minutes,
            'lane' => $lane,
        ]);
    }
}
