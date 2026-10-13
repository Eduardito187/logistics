<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;

/**
 * Implementación Eloquent del repositorio de entregas.
 *
 * save() es un upsert transaccional: reescribe la fila de la entrega e inserta los escaneos nuevos.
 * Los escaneos son append-only: se insertan con updateOrCreate por id (inmutables, nunca se reescriben
 * de verdad) y el índice único en idempotency_key es el respaldo final de la idempotencia.
 */
final class EloquentDeliveryRepository implements DeliveryRepository
{
    public function __construct(
        private readonly DeliveryMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextIdentity(): DeliveryId
    {
        return DeliveryId::generate();
    }

    public function save(Delivery $delivery): void
    {
        $this->connections->connection()->transaction(function () use ($delivery): void {
            DeliveryRecord::query()->updateOrCreate(
                ['id' => $delivery->id()->toString()],
                $this->mapper->toDeliveryRow($delivery),
            );

            foreach ($delivery->scans() as $scan) {
                ScanEventRecord::query()->updateOrCreate(
                    ['id' => $scan->id->toString()],
                    $this->mapper->toScanRow($scan),
                );
            }
        });
    }

    public function findById(DeliveryId $id): ?Delivery
    {
        $record = DeliveryRecord::query()
            ->with(['scans'])
            ->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByShipmentId(string $shipmentId): ?Delivery
    {
        $record = DeliveryRecord::query()
            ->with(['scans'])
            ->where('shipment_id', $shipmentId)
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    /**
     * @return list<Delivery>
     */
    public function findByVehicleId(string $vehicleId): array
    {
        return DeliveryRecord::query()
            ->with(['scans'])
            ->where('vehicle_id', $vehicleId)
            ->orderBy('id')
            ->get()
            ->map(fn (DeliveryRecord $record): Delivery => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
