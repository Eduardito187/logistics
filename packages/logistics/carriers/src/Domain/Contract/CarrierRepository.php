<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Contract;

use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Carriers\Domain\ValueObject\CarrierId;

/**
 * Puerto de persistencia de transportistas. La implementación vive en Infrastructure.
 */
interface CarrierRepository
{
    public function nextIdentity(): CarrierId;

    public function save(Carrier $carrier): void;

    public function findById(CarrierId $id): ?Carrier;

    /**
     * @return list<Carrier>
     */
    public function all(): array;
}
