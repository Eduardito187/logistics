<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Application\ReadModel\PrinterView;

/**
 * Lado lectura del registro de impresoras (ReadModel optimizado, sin pasar por el agregado).
 */
interface PrinterReader
{
    /**
     * Impresoras activas. Con `$allNodes` devuelve todas; si no, las de `$nodeId` más las compartidas
     * (sin nodo). Orden estable: nombre.
     *
     * @return list<PrinterView>
     */
    public function available(?string $nodeId, bool $allNodes): array;
}
