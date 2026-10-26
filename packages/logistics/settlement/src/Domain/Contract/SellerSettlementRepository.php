<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Contract;

use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Settlement\Domain\ValueObject\SettlementId;

/**
 * Puerto de persistencia de liquidaciones de sellers. La implementación vive en Infrastructure.
 */
interface SellerSettlementRepository
{
    public function nextIdentity(): SettlementId;

    public function save(SellerSettlement $settlement): void;

    public function findById(SettlementId $id): ?SellerSettlement;

    /**
     * Lista liquidaciones, con filtros opcionales por seller, estado y periodo (solapamiento del rango).
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<SellerSettlement>
     */
    public function list(
        ?string $sellerId = null,
        ?string $status = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * Cuenta las liquidaciones que cumplen los MISMOS filtros que `list`, para el total de la paginación.
     */
    public function countBy(
        ?string $sellerId = null,
        ?string $status = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
    ): int;
}
