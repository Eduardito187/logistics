<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Throwable;

/**
 * Adaptador que arma los pedidos pendientes para el auto-planificador leyendo el contexto de Envíos y
 * resolviendo el departamento con el localizador de Cobertura. Vive en Infrastructure: conoce los
 * detalles de otros contextos (de solo lectura, nunca los modifica) y los traduce al contrato de
 * dominio DeliveryCandidate.
 *
 * Qué entra en el plan del día X (regla de fecha):
 *
 *  1. Envíos RECEIVED (todavía sin plan) con coordenadas y `delivery_date = X` (la fecha prometida).
 *  2. SOLO si X es HOY (reloj del negocio): además los RECEIVED sin fecha prometida (`delivery_date`
 *     null) y los atrasados (`delivery_date < hoy`). Así no se pierden: un envío sin fecha o cuya fecha
 *     ya pasó sin plan se reparte en la primera planificación del día en curso, y nunca se adelanta a un
 *     plan futuro (un plan de mañana no "roba" envíos sin fecha ni de otro día).
 *  3. Los envíos del BACKLOG de replanificación pendientes para X (p. ej. los que quedaron sin entregar
 *     por un incidente MAYOR del camión), sin importar su `delivery_date`, siempre que no estén en un
 *     estado terminal (entregado/fallido/anulado). El auto-planificador los marca consumidos cuando un
 *     plan generado los incluye.
 *
 * Carga: kg/m3 reales desde las medidas por línea (ShipmentLoadReader), con fallback a los defaults por
 * producto y marca de "carga estimada" en el candidato. Requisitos de ruteo (R-04): leídos solo para los
 * envíos del lote. Cada lectura va en LOTES (chunkById + whereIn por lote), sin N+1, para escalar a miles de
 * pedidos sin cargarlos todos de golpe. La franja se lee de la ventana horaria del envío
 * (delivery_window_start/end) si la trae.
 */
final readonly class EloquentPendingDeliveryProvider implements PendingDeliveryProvider
{
    /** Estado de envío recién recibido y aún sin planificar. */
    private const PENDING_STATUS = 'RECEIVED';

    /**
     * Estados NO terminales en los que un envío del backlog de replanificación puede volver a planificarse
     * (tras un incidente puede seguir figurando como planificado o en ejecución).
     */
    private const REPLANNABLE_STATUSES = ['RECEIVED', 'PLANNED', 'IN_EXECUTION'];

    /** Tamaño del lote de lectura: acota la memoria con grandes volúmenes. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private DepartmentLocator $locator,
        private ShipmentLoadReader $loads,
        private ReplanBacklogRepository $backlog,
        private Clock $clock,
    ) {}

    public function pendingFor(PlanDate $date): array
    {
        $day = $date->toString();
        $isToday = $day === $this->clock->now()->format('Y-m-d');
        $replanIds = $this->backlog->pendingShipmentIdsFor($date);

        /** @var list<DeliveryCandidate> $candidates */
        $candidates = [];

        ShipmentRecord::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->where(function (Builder $query) use ($day, $isToday, $replanIds): void {
                // (1) y (2): recibidos para el día del plan (y, si es hoy, sin fecha o atrasados).
                $query->where(function (Builder $received) use ($day, $isToday): void {
                    $received->where('status', self::PENDING_STATUS)
                        ->where(function (Builder $byDate) use ($day, $isToday): void {
                            $byDate->where('delivery_date', $day);
                            if ($isToday) {
                                $byDate->orWhereNull('delivery_date')
                                    ->orWhere('delivery_date', '<', $day);
                            }
                        });
                });

                // (3): backlog de replanificación pendiente para el día, en estado no terminal.
                if ($replanIds !== []) {
                    $query->orWhere(function (Builder $replan) use ($replanIds): void {
                        $replan->whereIn('id', $replanIds)
                            ->whereIn('status', self::REPLANNABLE_STATUSES);
                    });
                }
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use (&$candidates): void {
                /** @var Collection<int, ShipmentRecord> $chunk */
                $ids = [];
                foreach ($chunk as $record) {
                    $ids[] = (string) $record->id;
                }

                // Carga y requisitos EN LOTE, solo de los envíos de este lote (whereIn, sin N+1).
                $loads = $this->loads->loadsFor($ids);
                $requirements = $this->shipmentRequirements($ids);

                foreach ($chunk as $record) {
                    $candidate = $this->toCandidate($record, $loads, $requirements);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            });

        return $candidates;
    }

    /**
     * Traduce una fila de envío a un candidato, resolviendo departamento y su carga. Devuelve null si el
     * envío no tiene coordenadas numéricas (no se puede rutear ni ubicar).
     *
     * @param array<string, array{kg: float, m3: float, estimated: bool}> $loads        carga por envío
     * @param array<string, ShipmentRouteRequirement>                     $requirements requisitos (R-04) por envío
     */
    private function toCandidate(ShipmentRecord $record, array $loads, array $requirements): ?DeliveryCandidate
    {
        if (!is_numeric($record->lat) || !is_numeric($record->lng)) {
            return null;
        }

        $lat = (float) $record->lat;
        $lng = (float) $record->lng;
        $shipmentId = (string) $record->id;

        $location = $this->locator->locate(GeoPoint::of($lat, $lng));
        $departmentId = $location?->departmentId;
        $zoneKey = $location->cityId ?? $location?->departmentId;

        // El lector siempre devuelve una entrada por id; el default defensivo replica "un producto estimado".
        $load = $loads[$shipmentId] ?? null;

        // La franja viaja en la ventana horaria del envío (delivery_window_start/end, 'HH:MM'). La clave de
        // agrupamiento del modo slots se deriva de esa misma ventana para que un camión sirva una sola franja.
        $windowStart = is_string($record->delivery_window_start) ? $record->delivery_window_start : null;
        $windowEnd = is_string($record->delivery_window_end) ? $record->delivery_window_end : null;
        $slot = $windowStart !== null && $windowEnd !== null ? "{$windowStart}-{$windowEnd}" : null;

        return DeliveryCandidate::of(
            shipmentId: $shipmentId,
            latitude: $lat,
            longitude: $lng,
            weightKg: $load['kg'] ?? 0.0,
            volumeM3: $load['m3'] ?? 0.0,
            departmentId: $departmentId,
            zoneKey: $zoneKey,
            deliverySlot: $slot,
            orderReference: $record->external_id,
            customerName: $record->customer_name,
            address: $record->address_text,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            requirement: $requirements[$shipmentId] ?? null,
            loadEstimated: $load['estimated'] ?? true,
        );
    }

    /**
     * Requisitos de ruteo (R-04) de los envíos dados, leídos EN LOTE (whereIn) del read-side propio de
     * Planificación (planning_shipment_requirements). Tolerante: si la tabla aún no existe (migración sin
     * aplicar), degrada a sin requisitos para no romper la planificación.
     *
     * @param  list<string>                            $shipmentIds
     * @return array<string, ShipmentRouteRequirement>
     */
    private function shipmentRequirements(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        try {
            $rows = DB::table('planning_shipment_requirements')->whereIn('shipment_id', $shipmentIds)->get();
        } catch (Throwable) {
            return [];
        }

        $requirements = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $shipmentId = isset($data['shipment_id']) && is_scalar($data['shipment_id']) ? (string) $data['shipment_id'] : '';
            if ($shipmentId === '') {
                continue;
            }

            $requirements[$shipmentId] = ShipmentRouteRequirement::fromArray([
                'load_type' => $data['load_type'] ?? null,
                'zone' => $data['zone'] ?? null,
                'height_m' => $data['height_m'] ?? null,
                'weight_kg' => $data['weight_kg'] ?? null,
            ]);
        }

        return $requirements;
    }
}
