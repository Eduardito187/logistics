<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\Contract;

use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\ReturnId;

/**
 * Puerto de persistencia del agregado ReturnOrder. La implementación vive en Infrastructure.
 */
interface ReturnOrderRepository
{
    public function nextIdentity(): ReturnId;

    /**
     * Upsert de la devolución por id (incluye sus líneas).
     */
    public function save(ReturnOrder $returnOrder): void;

    public function findById(ReturnId $id): ?ReturnOrder;

    /**
     * Lista devoluciones filtrando por seller dueño y/o estado (cualquiera nullable = sin filtro).
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<ReturnOrder>
     */
    public function listBy(?string $ownerPartyId = null, ?string $status = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * Cuenta las devoluciones que cumplen los MISMOS filtros que `listBy`, para el total de la paginación.
     */
    public function countBy(?string $ownerPartyId = null, ?string $status = null): int;
}
