<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Application\ReadModel\LabelUnitView;

/**
 * Puerto que resuelve una etiqueta (su id/ULID) al BULTO FÍSICO (PhysicalUnit) y su envío. Cruza labels →
 * shipments en SOLO lectura, tolerante (si falta algo, devuelve una resolución `resolved=false`). La
 * implementación (Infrastructure) correlaciona por (orden→envío, línea, índice de unidad e índice de pieza).
 */
interface LabelUnitResolver
{
    public function resolve(string $labelId): LabelUnitView;
}
