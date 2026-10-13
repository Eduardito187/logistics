<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DriverId;

/**
 * Puerto de persistencia de choferes. La implementación vive en Infrastructure.
 */
interface DriverRepository
{
    public function nextIdentity(): DriverId;

    public function save(Driver $driver): void;

    public function findById(DriverId $id): ?Driver;

    /**
     * @return list<Driver>
     */
    public function all(): array;
}
