<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Implementación Eloquent del repositorio de envíos.
 *
 * save() es un upsert transaccional: reescribe la fila del envío y regenera sus líneas y bultos
 * (borrar e insertar) para que la persistencia refleje exactamente el estado del agregado. La
 * idempotencia de ingreso se respalda en el índice único (source, external_id).
 */
final class EloquentShipmentRepository implements ShipmentRepository
{
    public function __construct(
        private readonly ShipmentMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextIdentity(): ShipmentId
    {
        return ShipmentId::generate();
    }

    public function save(Shipment $shipment): void
    {
        $this->connections->connection()->transaction(function () use ($shipment): void {
            ShipmentRecord::query()->updateOrCreate(
                ['id' => $shipment->id()->toString()],
                $this->mapper->toShipmentRow($shipment),
            );

            $shipmentId = $shipment->id()->toString();

            // Regenera líneas y bultos: el agregado es la fuente de verdad de ambos.
            ShipmentLineRecord::query()->where('shipment_id', $shipmentId)->delete();
            PhysicalUnitRecord::query()->where('shipment_id', $shipmentId)->delete();

            foreach ($shipment->lines() as $line) {
                ShipmentLineRecord::query()->create($this->mapper->toLineRow($shipment, $line));
            }
            foreach ($shipment->physicalUnits() as $unit) {
                PhysicalUnitRecord::query()->create($this->mapper->toUnitRow($shipment, $unit));
            }
        });
    }

    public function findById(ShipmentId $id): ?Shipment
    {
        $record = ShipmentRecord::query()
            ->with(['lines', 'units'])
            ->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findBySourceReference(SourceReference $reference): ?Shipment
    {
        $record = ShipmentRecord::query()
            ->with(['lines', 'units'])
            ->where('source', $reference->source->value)
            ->where('external_id', $reference->externalId)
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function all(): array
    {
        return ShipmentRecord::query()
            ->with(['lines', 'units'])
            ->orderBy('id')
            ->get()
            ->map(fn (ShipmentRecord $record): Shipment => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
