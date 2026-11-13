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
 * save() es un upsert transaccional: reescribe la fila del envío y SINCRONIZA sus líneas y bultos por
 * id (upsert de las presentes en el agregado + borrado de las que ya no están), sin borrar y reinsertar
 * todo. Así una línea o un bulto que ya existía conserva su fila —y por lo tanto su id— a través de
 * cualquier guardado (cambio de estado, reprogramación, reasignación de vehículo); los escaneos y
 * etiquetas que referencian el id del bulto siguen siendo válidos. La idempotencia de ingreso se
 * respalda en el índice único (source, external_id).
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

            // El agregado es la fuente de verdad: tras save() la base refleja EXACTAMENTE sus líneas y
            // bultos. Pero se sincroniza por id en lugar de borrar+reinsertar, para no cambiar el id de
            // una fila que ya existía (ver docblock de la clase).
            $this->syncLines($shipment);
            $this->syncUnits($shipment);
        });
    }

    /**
     * Sincroniza las líneas del envío contra la base: upsert por id de las que trae el agregado y
     * borrado de las que ya no están. Cada línea existente conserva su fila (y su id).
     */
    private function syncLines(Shipment $shipment): void
    {
        $keptIds = [];
        foreach ($shipment->lines() as $line) {
            $row = $this->mapper->toLineRow($shipment, $line);
            ShipmentLineRecord::query()->updateOrCreate(['id' => $row['id']], $row);
            $keptIds[] = $row['id'];
        }

        $query = ShipmentLineRecord::query()->where('shipment_id', $shipment->id()->toString());
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }
        $query->delete();
    }

    /**
     * Sincroniza los bultos del envío contra la base: upsert por id de los que trae el agregado y
     * borrado de los que ya no están. Cada bulto existente conserva su fila (y su id), de modo que los
     * escaneos y etiquetas que lo referencian siguen apuntando al mismo bulto.
     */
    private function syncUnits(Shipment $shipment): void
    {
        $keptIds = [];
        foreach ($shipment->physicalUnits() as $unit) {
            $row = $this->mapper->toUnitRow($shipment, $unit);
            PhysicalUnitRecord::query()->updateOrCreate(['id' => $row['id']], $row);
            $keptIds[] = $row['id'];
        }

        $query = PhysicalUnitRecord::query()->where('shipment_id', $shipment->id()->toString());
        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }
        $query->delete();
    }

    public function findById(ShipmentId $id): ?Shipment
    {
        $record = ShipmentRecord::query()
            ->with(['lines', 'units'])
            ->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByIdForUpdate(ShipmentId $id): ?Shipment
    {
        // Bloquea SOLO la fila del envío (las líneas/bultos se cargan aparte, sin bloqueo): basta para
        // serializar los cambios de estado concurrentes sobre el mismo envío.
        $record = ShipmentRecord::query()
            ->whereKey($id->toString())
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            return null;
        }

        $record->load(['lines', 'units']);

        return $this->mapper->toDomain($record);
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
