<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shipments\Domain\Contract\ShipmentLegRepository;
use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLegId;

/**
 * Implementación Eloquent del repositorio de tramos (el agregado {@see ShipmentItinerary}).
 *
 * save() es un upsert transaccional: sincroniza los tramos del itinerario por id (upsert de los presentes
 * + borrado de los que ya no están), sin borrar y reinsertar todo. Así un tramo que ya existía conserva su
 * fila —y su id— a través de cualquier guardado. El orden de carga lo garantiza el único (shipment_id, seq)
 * y el ORDER BY `seq` de la lectura.
 */
final class EloquentShipmentLegRepository implements ShipmentLegRepository
{
    public function __construct(
        private readonly ShipmentLegMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextLegIdentity(): ShipmentLegId
    {
        return ShipmentLegId::generate();
    }

    public function save(ShipmentItinerary $itinerary): void
    {
        $this->connections->connection()->transaction(function () use ($itinerary): void {
            $keptIds = [];
            foreach ($itinerary->legs() as $leg) {
                $row = $this->mapper->toLegRow($itinerary, $leg);
                ShipmentLegRecord::query()->updateOrCreate(['id' => $row['id']], $row);
                $keptIds[] = $row['id'];
            }

            $query = ShipmentLegRecord::query()->where('shipment_id', $itinerary->shipmentId()->toString());
            if ($keptIds !== []) {
                $query->whereNotIn('id', $keptIds);
            }
            $query->delete();
        });
    }

    public function findByShipmentId(ShipmentId $shipmentId): ShipmentItinerary
    {
        $records = ShipmentLegRecord::query()
            ->where('shipment_id', $shipmentId->toString())
            ->orderBy('seq')
            ->get();

        return $this->mapper->toDomain($shipmentId, $records);
    }

    public function hasLegs(ShipmentId $shipmentId): bool
    {
        return ShipmentLegRecord::query()
            ->where('shipment_id', $shipmentId->toString())
            ->exists();
    }
}
