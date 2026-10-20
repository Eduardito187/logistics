<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Repository;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Mapper\AsnMapper;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de ASNs. `save` es un upsert por id del ASN; las líneas se
 * reemplazan en bloque (recepción idempotente). Las líneas llevan su propio UUID v7.
 */
final readonly class EloquentAsnRepository implements AsnRepository
{
    public function __construct(
        private AsnMapper $mapper = new AsnMapper,
    ) {}

    public function nextIdentity(): AsnId
    {
        return AsnId::generate();
    }

    public function save(Asn $asn): void
    {
        $row = $this->mapper->toRow($asn);
        $record = AsnRecord::query()->updateOrCreate(['id' => $row['id']], $row);

        // Reemplaza las líneas: se borran y se reinsertan con UUID v7 propio (el ASN es la raíz).
        $record->lines()->delete();
        foreach ($this->mapper->toLineRows($asn) as $line) {
            $record->lines()->create([
                'id' => Uuid::generate()->value,
                'sku' => $line['sku'],
                'expected_quantity' => $line['expected_quantity'],
            ]);
        }
    }

    public function findById(AsnId $id): ?Asn
    {
        $record = AsnRecord::query()->with('lines')->find($id->toString());

        return $record instanceof AsnRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(
        ?string $sellerId = null,
        ?string $warehouseId = null,
        ?string $status = null,
        ?string $appointmentDate = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        // Orden determinista para la paginación: cita (nulls last), created_at y desempate por id.
        $query = $this->filtered($sellerId, $warehouseId, $status, $appointmentDate)
            ->with('lines')
            ->orderByRaw('appointment_date is null, appointment_date asc, created_at asc')
            ->orderBy('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        // Sin recorte explícito se conserva el tope sano histórico (evita volcar toda la tabla).
        $query->limit($limit ?? 1000);

        $records = $query->get();

        return array_values($records->map(fn (AsnRecord $record): Asn => $this->mapper->toDomain($record))->all());
    }

    public function countBy(
        ?string $sellerId = null,
        ?string $warehouseId = null,
        ?string $status = null,
        ?string $appointmentDate = null,
    ): int {
        return $this->filtered($sellerId, $warehouseId, $status, $appointmentDate)->count();
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden, recorte ni eager load).
     *
     * @return Builder<AsnRecord>
     */
    private function filtered(
        ?string $sellerId,
        ?string $warehouseId,
        ?string $status,
        ?string $appointmentDate,
    ): Builder {
        return AsnRecord::query()
            ->when($sellerId !== null, static function (Builder $query) use ($sellerId): void {
                $query->where('seller_id', $sellerId);
            })
            ->when($warehouseId !== null, static function (Builder $query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId);
            })
            ->when($status !== null, static function (Builder $query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($appointmentDate !== null, static function (Builder $query) use ($appointmentDate): void {
                $query->where('appointment_date', $appointmentDate);
            });
    }

    public function listScheduledBetween(?string $warehouseId, string $fromDate, string $toDate): array
    {
        $records = AsnRecord::query()
            ->with('lines')
            ->where('status', 'scheduled')
            ->whereNotNull('appointment_date')
            ->whereBetween('appointment_date', [$fromDate, $toDate])
            ->when($warehouseId !== null, static function (Builder $query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId);
            })
            ->orderBy('appointment_date')
            ->orderBy('appointment_start')
            ->limit(2000)
            ->get();

        return array_values($records->map(fn (AsnRecord $record): Asn => $this->mapper->toDomain($record))->all());
    }
}
