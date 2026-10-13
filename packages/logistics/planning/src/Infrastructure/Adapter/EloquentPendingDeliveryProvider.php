<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Collection;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Adaptador que arma los pedidos pendientes para el auto-planificador leyendo el contexto de Envíos y
 * resolviendo el departamento con el localizador de Cobertura. Vive en Infrastructure: conoce los
 * detalles de otros contextos (de solo lectura, nunca los modifica) y los traduce al contrato de
 * dominio DeliveryCandidate.
 *
 * Pendientes = envíos en estado RECEIVED (todavía sin plan) con coordenadas. Lee en LOTES (chunkById)
 * para escalar a miles de pedidos sin cargarlos todos de golpe. El peso y el volumen se estiman por la
 * cantidad de bultos del pedido por los valores por bulto configurables (settings/config), hasta que
 * Envíos publique peso/volumen reales. La franja (delivery_slot) se lee si el envío la trae.
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

        /** @var list<DeliveryCandidate> $candidates */
        $candidates = [];

        ShipmentRecord::query()
            ->where('status', self::PENDING_STATUS)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->withSum('lines', 'quantity')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use (&$candidates, $defaultWeight, $defaultVolume): void {
                /** @var Collection<int, ShipmentRecord> $chunk */
                foreach ($chunk as $record) {
                    $candidate = $this->toCandidate($record, $defaultWeight, $defaultVolume);
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
     */
    private function toCandidate(ShipmentRecord $record, float $defaultWeight, float $defaultVolume): ?DeliveryCandidate
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

        /** @var mixed $slot */
        $slot = $record->getAttributes()['delivery_slot'] ?? null;

        return DeliveryCandidate::of(
            shipmentId: (string) $record->id,
            latitude: $lat,
            longitude: $lng,
            weightKg: $packages * $defaultWeight,
            volumeM3: $packages * $defaultVolume,
            departmentId: $departmentId,
            zoneKey: $zoneKey,
            deliverySlot: is_string($slot) ? $slot : null,
            orderReference: $record->external_id,
            customerName: $record->customer_name,
            address: $record->address_text,
        );
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_numeric($value) ? (float) $value : $default;
    }
}
