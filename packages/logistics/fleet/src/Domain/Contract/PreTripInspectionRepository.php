<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\PreTripInspectionId;

/**
 * Puerto de persistencia de inspecciones pre-salida. La implementación vive en Infrastructure.
 */
interface PreTripInspectionRepository
{
    public function nextIdentity(): PreTripInspectionId;

    public function save(PreTripInspection $inspection): void;

    public function findById(PreTripInspectionId $id): ?PreTripInspection;

    /**
     * @return list<PreTripInspection>
     */
    public function all(): array;
}
