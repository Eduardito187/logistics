<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Query\GetCompensationCase;

use Logistics\Shared\Application\Query\Query;

/**
 * Devuelve un caso de compensación por su id (o null si no existe).
 */
final readonly class GetCompensationCase implements Query
{
    public function __construct(
        public string $caseId,
    ) {}
}
