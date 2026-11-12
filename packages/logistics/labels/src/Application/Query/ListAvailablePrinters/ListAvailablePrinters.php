<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\ListAvailablePrinters;

use Logistics\Labels\Application\ReadModel\PrinterView;
use Logistics\Shared\Application\Query\Query;

/**
 * Impresoras ACTIVAS que la app puede ofrecer a un usuario ("Impresoras de tu sede").
 *
 * - `allNodes = true` (supervisor/admin): todas las activas, de cualquier sede.
 * - si no: las de `nodeId` + las compartidas (sin nodo). Sin nodo asignado: solo las compartidas.
 *
 * Devuelve list<{@see PrinterView}>.
 */
final readonly class ListAvailablePrinters implements Query
{
    public function __construct(
        public ?string $nodeId,
        public bool $allNodes = false,
    ) {}
}
