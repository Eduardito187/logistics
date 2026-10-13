<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Contract;

use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;

/**
 * Puerto de persistencia de la jerarquía administrativa.
 */
interface GeoAreaRepository
{
    public function nextIdentity(): GeoAreaId;

    public function save(GeoArea $area): void;

    public function findById(GeoAreaId $id): ?GeoArea;

    /**
     * @return array<int, GeoArea>
     */
    public function all(): array;
}
