<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Throwable;

/**
 * Adaptador que arma los pedidos pendientes para el auto-planificador leyendo el contexto de Envíos y
 * resolviendo el departamento con el localizador de Cobertura. Vive en Infrastructure: conoce los
 * detalles de otros contextos (de solo lectura, nunca los modifica) y los traduce al contrato de
 * dominio DeliveryCandidate.
 *
 * Pendientes = envíos en estado RECEIVED (todavía sin plan) con coordenadas. Lee en LOTES (chunkById)
 * para escalar a miles de pedidos sin cargarlos todos de golpe. El peso y el volumen se estiman por la
 * cantidad de bultos del pedido por los valores por bulto configurables (settings/config), hasta que
 * Envíos publique peso/volumen reales. La franja se lee de la ventana horaria del envío
 * (delivery_window_start/end) si la trae, y alimenta tanto el agrupamiento por slots como la restricción
 * de ventanas del ruteador.
 *
 * Nota: hoy no se filtra por fecha porque el envío aún no lleva fecha prometida; cuando la lleve, el
 * filtro por $date entra acá sin tocar el resto del motor.
 */
final readonly class EloquentPendingDeliveryProvider implements PendingDeliveryProvider
{
    /** Estado de envío recién recibido y aún sin planificar. */
    private const PENDING_STATUS = 'RECEIVED';

    /** Tamaño del lote de lectura: acota la memoria con grandes volúmenes. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private DepartmentLocator $locator,
        private Config $config,
    ) {}

    public function pendingFor(PlanDate $date): array
    {
        $defaultWeight = $this->floatConfig('default_weight_kg_per_package', 10.0);
        $defaultVolume = $this->floatConfig('default_volume_m3_per_package', 0.05);
        $requirements = $this->shipmentRequirements();

        /** @var list<DeliveryCandidate> $candidates */
        $candidates = [];

        ShipmentRecord::query()
            ->where('status', self::PENDING_STATUS)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->withSum('lines', 'quantity')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use (&$candidates, $defaultWeight, $defaultVolume, $requirements): void {
                /** @var Collection<int, ShipmentRecord> $chunk */
                foreach ($chunk as $record) {
                    $candidate = $this->toCandidate($record, $defaultWeight, $defaultVolume, $requirements);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            });

        return $candidates;
    }

    /**
     * Traduce una fila de envío a un candidato, resolviendo departamento y estimando la carga. Devuelve
     * null si el envío no tiene coordenadas numéricas (no se puede rutear ni ubicar).
     *
     * @param array<string, ShipmentRouteRequirement> $requirements requisitos de ruteo (R-04) por envío
     */
    private function toCandidate(ShipmentRecord $record, float $defaultWeight, float $defaultVolume, array $requirements): ?DeliveryCandidate
    {
        if (!is_numeric($record->lat) || !is_numeric($record->lng)) {
            return null;
        }

        $lat = (float) $record->lat;
        $lng = (float) $record->lng;

        $location = $this->locator->locate(GeoPoint::of($lat, $lng));
        $departmentId = $location?->departmentId;
        $zoneKey = $location->cityId ?? $location?->departmentId;

        // Cantidad de bultos: suma de cantidades de las líneas (agregada en la consulta), mínimo 1.
        $packagesRaw = $record->getAttribute('lines_sum_quantity');
        $packages = is_numeric($packagesRaw) ? max(1, (int) $packagesRaw) : 1;

        // La franja viaja en la ventana horaria del envío (delivery_window_start/end, 'HH:MM'). La clave de
        // agrupamiento del modo slots se deriva de esa misma ventana para que un camión sirva una sola franja.
        $windowStart = is_string($record->delivery_window_start) ? $record->delivery_window_start : null;
        $windowEnd = is_string($record->delivery_window_end) ? $record->delivery_window_end : null;
        $slot = $windowStart !== null && $windowEnd !== null ? "{$windowStart}-{$windowEnd}" : null;

        return DeliveryCandidate::of(
            shipmentId: (string) $record->id,
            latitude: $lat,
            longitude: $lng,
            weightKg: $packages * $defaultWeight,
            volumeM3: $packages * $defaultVolume,
            departmentId: $departmentId,
            zoneKey: $zoneKey,
            deliverySlot: $slot,
            orderReference: $record->external_id,
            customerName: $record->customer_name,
            address: $record->address_text,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            requirement: $requirements[(string) $record->id] ?? null,
        );
    }

    /**
     * Requisitos de ruteo (R-04) por envío, leídos EN LOTE del read-side propio de Planificación
     * (planning_shipment_requirements). Tolerante: si la tabla aún no existe (migración sin aplicar),
     * degrada a sin requisitos para no romper la planificación.
     *
     * @return array<string, ShipmentRouteRequirement>
     */
    private function shipmentRequirements(): array
    {
        try {
            $rows = DB::table('planning_shipment_requirements')->get();
        } catch (Throwable) {
            return [];
        }

        $requirements = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $shipmentId = isset($data['shipment_id']) ? (string) $data['shipment_id'] : '';
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

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_numeric($value) ? (float) $value : $default;
    }
}
