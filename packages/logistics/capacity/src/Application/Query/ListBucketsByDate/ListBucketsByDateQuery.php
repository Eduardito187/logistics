<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\ListBucketsByDate;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los cupos de una fecha. Sin municipio, trae todos los scopes de ese día; con municipio, solo los
 * cupos de ese municipio (el eje principal del calendario operativo).
 */
final readonly class ListBucketsByDateQuery implements Query
{
    public function __construct(
        public string $date,
        public ?string $municipalityId = null,
    ) {}
}
