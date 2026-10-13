<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\ListBucketsByDate;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista todos los cupos de una fecha (todos los scopes, franjas y unidades de ese día).
 */
final readonly class ListBucketsByDateQuery implements Query
{
    public function __construct(public string $date) {}
}
