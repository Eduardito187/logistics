<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Persistence\Eloquent\Repository;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Mapper\SellerSettlementMapper;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementLineRecord;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de liquidaciones de sellers.
 *
 * `save` es un upsert por id de la cabecera; las líneas son inmutables tras el borrado (nunca cambian una
 * vez agregadas), así que se regeneran de forma idempotente: se borran las existentes y se reinsertan.
 * Todo corre dentro de la transacción que abre el decorador transaccional del CommandBus.
 */
final readonly class EloquentSellerSettlementRepository implements SellerSettlementRepository
{
    public function __construct(
        private SellerSettlementMapper $mapper = new SellerSettlementMapper,
    ) {}

    public function nextIdentity(): SettlementId
    {
        return SettlementId::generate();
    }

    public function save(SellerSettlement $settlement): void
    {
        $row = $this->mapper->toRow($settlement);
        SellerSettlementRecord::query()->updateOrCreate(['id' => $row['id']], $row);

        SellerSettlementLineRecord::query()->where('settlement_id', $row['id'])->delete();
        foreach ($this->mapper->lineRows($settlement) as $lineRow) {
            SellerSettlementLineRecord::query()->create(['id' => Uuid::generate()->value] + $lineRow);
        }
    }

    public function findById(SettlementId $id): ?SellerSettlement
    {
        $record = SellerSettlementRecord::query()->with('lines')->find($id->toString());

        return $record instanceof SellerSettlementRecord ? $this->mapper->toDomain($record) : null;
    }

    public function list(
        ?string $sellerId = null,
        ?string $status = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        // Desempate por id (UUID v7) para que la paginación sea determinista ante periodos repetidos.
        $query = $this->filtered($sellerId, $status, $periodFrom, $periodTo)
            ->with('lines')->orderByDesc('period_from')->orderBy('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (SellerSettlementRecord $record): SellerSettlement => $this->mapper->toDomain($record))
            ->all());
    }

    public function countBy(
        ?string $sellerId = null,
        ?string $status = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
    ): int {
        return $this->filtered($sellerId, $status, $periodFrom, $periodTo)->count();
    }

    /**
     * Query builder con los filtros comunes de list/countBy (sin orden, recorte ni eager load).
     *
     * @return Builder<SellerSettlementRecord>
     */
    private function filtered(
        ?string $sellerId,
        ?string $status,
        ?string $periodFrom,
        ?string $periodTo,
    ): Builder {
        $query = SellerSettlementRecord::query();

        if ($sellerId !== null && $sellerId !== '') {
            $query->where('seller_id', $sellerId);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        // Solapamiento de rangos: la liquidación toca el periodo pedido.
        if ($periodTo !== null && $periodTo !== '') {
            $query->where('period_from', '<=', $periodTo);
        }
        if ($periodFrom !== null && $periodFrom !== '') {
            $query->where('period_to', '>=', $periodFrom);
        }

        return $query;
    }
}
