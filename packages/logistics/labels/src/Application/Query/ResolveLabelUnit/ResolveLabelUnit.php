<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\ResolveLabelUnit;

use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Query\Query;

/**
 * Resuelve una etiqueta escaneada (su `id`/ULID del QR) al bulto físico y su envío, para que la app pueda
 * registrar el escaneo contra el bulto real (y cerrar el tracking). Ver {@see LabelUnitView}.
 */
final readonly class ResolveLabelUnit implements Query
{
    public function __construct(
        public string $labelId,
    ) {}
}
