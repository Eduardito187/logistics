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
 * save() es un upsert transaccional por la clave natural (shipment_id, seq): sincroniza los tramos del
 * itinerario sin borrar y reinsertar todo. Un tramo que ya existía en esa posición conserva su fila —y su
 * id—, aunque el agregado se haya rearmado con ids nuevos (el planner regenera ids al replanificar). Así la
 * reconstrucción nunca choca con el único (shipment_id, seq). El ORDER BY `seq` de la lectura da el orden.
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
            $shipmentId = $itinerary->shipmentId()->toString();
            $keptSeqs = [];
            foreach ($itinerary->legs() as $leg) {
                $row = $this->mapper->toLegRow($itinerary, $leg);
                // Upsert por (shipment_id, seq): si ese tramo ya existe conserva su fila y su id (no se
                // reinserta con un id nuevo, que chocaría con el único antes de poder borrar el viejo).
                $existing = ShipmentLegRecord::query()
                    ->where('shipment_id', $shipmentId)
                    ->where('seq', $row['seq'])
                    ->first();
                if ($existing !== null) {
                    unset($row['id']);
                    $existing->update($row);
                } else {
                    ShipmentLegRecord::query()->create($row);
                }
                $keptSeqs[] = $row['seq'];
            }

            $query = ShipmentLegRecord::query()->where('shipment_id', $shipmentId);
            if ($keptSeqs !== []) {
                $query->whereNotIn('seq', $keptSeqs);
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
