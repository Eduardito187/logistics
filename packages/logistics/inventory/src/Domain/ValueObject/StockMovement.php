<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Asiento del ledger de inventario (`inventory_movements`): un hecho inmutable de movimiento de stock,
 * append-only, para trazabilidad y reportería por dueño. No es un agregado; lo construye el handler a
 * partir de la operación ya ejecutada sobre el lote, y lo persiste el repositorio.
 */
final readonly class StockMovement
{
    public function __construct(
        public LotId $lotId,
        public string $warehouseId,
        public string $sku,
        public Ownership $ownership,
        public MovementType $type,
        public int $quantity,
        public ?string $reference,
        public ?string $relatedWarehouseId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
