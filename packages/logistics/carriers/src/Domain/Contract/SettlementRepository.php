<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Contract;

use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Domain\ValueObject\SettlementId;

/**
 * Puerto de persistencia de liquidaciones de transportistas. La implementación vive en Infrastructure.
 */
interface SettlementRepository
{
    public function nextIdentity(): SettlementId;

    public function save(Settlement $settlement): void;

    public function findById(SettlementId $id): ?Settlement;

    /**
     * Lista liquidaciones, con filtros opcionales por transportista y por estado.
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<Settlement>
     */
    public function list(?string $carrierId = null, ?string $status = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * Cuenta las liquidaciones que cumplen los MISMOS filtros que `list`, para el total de la paginación.
     */
    public function countBy(?string $carrierId = null, ?string $status = null): int;
}
