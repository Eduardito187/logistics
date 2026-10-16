<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un lote de stock. Por dentro es un UUID v7 (ver AbstractId): ordenable en el
 * tiempo, lo que mantiene compactos los índices aun con millones de lotes.
 */
final readonly class LotId extends AbstractId {}
