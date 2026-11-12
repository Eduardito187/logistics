<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\CrossContext;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Throwable;

/**
 * Custodia de envíos leída del contexto execution por query builder (solo lectura, sin modelos):
 *
 *  - vehículo y estado: `execution_deliveries` (una entrega por envío);
 *  - subida a bordo: el PRIMER escaneo LOAD (o IN_TRANSIT si no hubo LOAD) en `execution_scan_events`;
 *  - cierre: si la entrega está DELIVERED/FAILED, el último escaneo PROOF_OF_DELIVERY, o `pod_captured_at`,
 *    o en último caso `updated_at` de la entrega. Abierta (null) mientras siga a bordo.
 *
 * Tolerante: sin tablas o sin entrega devuelve null / lista vacía (SafeRead).
 */
final readonly class DbShipmentCustodyReader implements ShipmentCustodyReader
{
    /** Estados de entrega con el envío a bordo del vehículo. */
    private const array ONBOARD = ['LOADED', 'IN_TRANSIT'];

    /** Estados de entrega cerrados (el envío ya bajó del vehículo). */
    private const array CLOSED = ['DELIVERED', 'FAILED'];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function custodyOf(string $shipmentId): ?array
    {
        if (!SafeRead::isUuid($shipmentId)) {
            return null;
        }

        return SafeRead::run($this->connection, function () use ($shipmentId): ?array {
            $delivery = $this->connection->table('execution_deliveries')
                ->where('shipment_id', $shipmentId)
                ->first(['id', 'vehicle_id', 'status', 'pod_captured_at', 'updated_at']);
            if ($delivery === null) {
                return null;
            }

            $deliveryId = (string) $delivery->id;
            $status = (string) $delivery->status;

            $loadedAt = $this->firstScan($deliveryId, 'LOAD') ?? $this->firstScan($deliveryId, 'IN_TRANSIT');

            $closedAt = null;
            if (in_array($status, self::CLOSED, true)) {
                $closedAt = $this->lastScan($deliveryId, 'PROOF_OF_DELIVERY')
                    ?? self::date($delivery->pod_captured_at ?? null)
                    ?? self::date($delivery->updated_at ?? null);
            }

            return [
                'shipment_id' => $shipmentId,
                'vehicle_id' => (string) $delivery->vehicle_id,
                'status' => $status,
                'loaded_at' => $loadedAt,
                'closed_at' => $closedAt,
            ];
        }, null);
    }

    public function onboardShipmentIds(string $vehicleId): array
    {
        if (!SafeRead::isUuid($vehicleId)) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = SafeRead::run($this->connection, fn (): array => array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $this->connection->table('execution_deliveries')
                ->where('vehicle_id', $vehicleId)
                ->whereIn('status', self::ONBOARD)
                ->orderBy('shipment_id')
                ->pluck('shipment_id')
                ->all(),
        )), []);

        return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
    }

    private function firstScan(string $deliveryId, string $type): ?DateTimeImmutable
    {
        return self::date($this->connection->table('execution_scan_events')
            ->where('delivery_id', $deliveryId)
            ->where('type', $type)
            ->min('occurred_at'));
    }

    private function lastScan(string $deliveryId, string $type): ?DateTimeImmutable
    {
        return self::date($this->connection->table('execution_scan_events')
            ->where('delivery_id', $deliveryId)
            ->where('type', $type)
            ->max('occurred_at'));
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
