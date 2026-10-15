<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListDocuments;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los documentos de flota. Opcionalmente filtra por tipo de dueño (vehicle|driver|helper).
 */
final readonly class ListDocuments implements Query
{
    public function __construct(
        public ?string $ownerType = null,
    ) {}
}
