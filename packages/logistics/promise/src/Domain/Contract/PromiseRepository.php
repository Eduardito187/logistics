<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\PromiseId;

/**
 * Puerta de persistencia del agregado DeliveryPromise. El dominio la define; la implementación Eloquent
 * vive en Infrastructure.
 */
interface PromiseRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): PromiseId;

    public function save(DeliveryPromise $promise): void;

    public function findById(PromiseId $id): ?DeliveryPromise;
}
