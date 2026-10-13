<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;

/**
 * Puerto de persistencia de tripulaciones. La implementación vive en Infrastructure.
 */
interface CrewRepository
{
    public function nextIdentity(): CrewId;

    public function save(Crew $crew): void;

    public function findById(CrewId $id): ?Crew;

    /**
     * @return list<Crew>
     */
    public function all(): array;
}
