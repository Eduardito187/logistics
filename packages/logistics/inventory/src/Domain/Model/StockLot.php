<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Model;

use DateTimeImmutable;
use Logistics\Inventory\Domain\Event\StockAdjusted;
use Logistics\Inventory\Domain\Event\StockPicked;
use Logistics\Inventory\Domain\Event\StockReceived;
use Logistics\Inventory\Domain\Event\StockReleased;
use Logistics\Inventory\Domain\Event\StockReserved;
use Logistics\Inventory\Domain\Event\StockTransferred;
use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\Money;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Lote de stock: la unidad atómica de inventario CON dueño (Fase 3, ADR 017).
 *
 * El stock de distintos dueños puede estar físicamente mezclado (comingled) en el mismo almacén; lo que
 * segrega NO es una ubicación física, sino el lote: cada `StockLot` lleva su `Ownership` (propio de Dismac
 * o consignación de un seller). Esto es **segregación lógica**: la titularidad vive en el lote.
 *
 * Mantiene dos cantidades: `available` (libre para prometer/reservar) y `reserved` (comprometida pero aún
 * en el almacén). El on-hand físico es la suma de ambas. Un pick saca mercadería reservada del almacén
 * (baja el on-hand); una reserva solo mueve de disponible a reservada (el on-hand no cambia).
 */
final class StockLot extends AggregateRoot
{
    private function __construct(
        private readonly LotId $id,
        private readonly string $warehouseId,
        private readonly string $sku,
        private readonly Ownership $ownership,
        private int $availableQuantity,
        private int $reservedQuantity,
        private Money $unitCost,
        private readonly DateTimeImmutable $receivedAt,
        private readonly ?string $goodsReceiptRef,
    ) {}

    /**
     * Da de alta un lote nuevo al recibir stock. La cantidad entra como disponible; reservada arranca en 0.
     */
    public static function receive(
        LotId $id,
        string $warehouseId,
        string $sku,
        Ownership $ownership,
        int $quantity,
        Money $unitCost,
        DateTimeImmutable $receivedAt,
        ?string $goodsReceiptRef = null,
    ): self {
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            throw InventoryException::because('El almacén del lote no puede estar vacío.');
        }
        $sku = trim($sku);
        if ($sku === '') {
            throw InventoryException::because('El SKU del lote no puede estar vacío.');
        }
        if ($quantity <= 0) {
            throw InventoryException::because("La cantidad recibida debe ser positiva: {$quantity}.");
        }

        $lot = new self($id, $warehouseId, $sku, $ownership, $quantity, 0, $unitCost, $receivedAt, $goodsReceiptRef);
        $lot->recordEvent(new StockReceived(
            $id->toString(),
            $warehouseId,
            $sku,
            $ownership->mode->value,
            $ownership->ownerPartyId?->value,
            $ownership->sellerSkuRef,
            $quantity,
            $unitCost->cents,
            $unitCost->currency,
            $goodsReceiptRef,
        ));

        return $lot;
    }

    /**
     * Crea el lote destino de una transferencia SIN grabar StockReceived: el hecho ya lo cubre el
     * StockTransferred del lote origen. Conserva el mismo dueño (la transferencia no cambia titularidad).
     */
    public static function openFromTransfer(
        LotId $id,
        string $warehouseId,
        string $sku,
        Ownership $ownership,
        int $quantity,
        Money $unitCost,
        DateTimeImmutable $receivedAt,
        ?string $goodsReceiptRef = null,
    ): self {
        return new self($id, trim($warehouseId), trim($sku), $ownership, $quantity, 0, $unitCost, $receivedAt, $goodsReceiptRef);
    }

    /**
     * Reconstituye un lote desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        LotId $id,
        string $warehouseId,
        string $sku,
        Ownership $ownership,
        int $availableQuantity,
        int $reservedQuantity,
        Money $unitCost,
        DateTimeImmutable $receivedAt,
        ?string $goodsReceiptRef,
    ): self {
        return new self($id, $warehouseId, $sku, $ownership, $availableQuantity, $reservedQuantity, $unitCost, $receivedAt, $goodsReceiptRef);
    }

    /**
     * Reserva stock: mueve de disponible a reservado. No cambia el on-hand.
     */
    public function reserve(int $quantity, ?string $reference = null): void
    {
        $this->assertPositive($quantity);
        if ($quantity > $this->availableQuantity) {
            throw InventoryException::because(
                "No hay stock disponible suficiente para reservar {$quantity} (disponible: {$this->availableQuantity}).",
            );
        }

        $this->availableQuantity -= $quantity;
        $this->reservedQuantity += $quantity;
        $this->recordEvent(new StockReserved(
            $this->id->toString(),
            $this->warehouseId,
            $this->sku,
            $this->ownership->mode->value,
            $this->ownership->ownerPartyId?->value,
            $quantity,
            $reference,
        ));
    }

    /**
     * Libera una reserva: vuelve de reservado a disponible.
     */
    public function release(int $quantity, ?string $reference = null): void
    {
        $this->assertPositive($quantity);
        if ($quantity > $this->reservedQuantity) {
            throw InventoryException::because(
                "No se puede liberar {$quantity}: solo hay {$this->reservedQuantity} reservado.",
            );
        }

        $this->reservedQuantity -= $quantity;
        $this->availableQuantity += $quantity;
        $this->recordEvent(new StockReleased(
            $this->id->toString(),
            $this->warehouseId,
            $this->sku,
            $this->ownership->mode->value,
            $this->ownership->ownerPartyId?->value,
            $quantity,
            $reference,
        ));
    }

    /**
     * Pick: la mercadería reservada sale físicamente del almacén. Baja el on-hand (reservado).
     */
    public function pick(int $quantity, ?string $reference = null): void
    {
        $this->assertPositive($quantity);
        if ($quantity > $this->reservedQuantity) {
            throw InventoryException::because(
                "No se puede hacer pick de {$quantity}: solo hay {$this->reservedQuantity} reservado.",
            );
        }

        $this->reservedQuantity -= $quantity;
        $this->recordEvent(new StockPicked(
            $this->id->toString(),
            $this->warehouseId,
            $this->sku,
            $this->ownership->mode->value,
            $this->ownership->ownerPartyId?->value,
            $quantity,
            $reference,
        ));
    }

    /**
     * Transfiere stock disponible hacia otro almacén (salida del lote origen). El lote destino lo crea el
     * handler con `openFromTransfer`, conservando el mismo dueño.
     */
    public function transfer(int $quantity, string $destinationWarehouseId, ?string $reference = null): void
    {
        $this->assertPositive($quantity);
        $destinationWarehouseId = trim($destinationWarehouseId);
        if ($destinationWarehouseId === '') {
            throw InventoryException::because('El almacén destino no puede estar vacío.');
        }
        if ($destinationWarehouseId === $this->warehouseId) {
            throw InventoryException::because('No se puede transferir stock al mismo almacén.');
        }
        if ($quantity > $this->availableQuantity) {
            throw InventoryException::because(
                "No hay stock disponible suficiente para transferir {$quantity} (disponible: {$this->availableQuantity}).",
            );
        }

        $this->availableQuantity -= $quantity;
        $this->recordEvent(new StockTransferred(
            $this->id->toString(),
            $this->warehouseId,
            $destinationWarehouseId,
            $this->sku,
            $this->ownership->mode->value,
            $this->ownership->ownerPartyId?->value,
            $quantity,
            $reference,
        ));
    }

    /**
     * Ajusta el stock disponible en `$delta` (positivo o negativo). No puede dejar el disponible negativo.
     */
    public function adjust(int $delta, string $reason): void
    {
        if ($delta === 0) {
            throw InventoryException::because('El ajuste no puede ser cero.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw InventoryException::because('El ajuste de inventario requiere un motivo.');
        }
        $newAvailable = $this->availableQuantity + $delta;
        if ($newAvailable < 0) {
            throw InventoryException::because(
                "El ajuste dejaría el stock disponible en negativo ({$newAvailable}).",
            );
        }

        $this->availableQuantity = $newAvailable;
        $this->recordEvent(new StockAdjusted(
            $this->id->toString(),
            $this->warehouseId,
            $this->sku,
            $this->ownership->mode->value,
            $this->ownership->ownerPartyId?->value,
            $delta,
            $reason,
        ));
    }

    public function id(): LotId
    {
        return $this->id;
    }

    public function warehouseId(): string
    {
        return $this->warehouseId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function ownership(): Ownership
    {
        return $this->ownership;
    }

    public function availableQuantity(): int
    {
        return $this->availableQuantity;
    }

    public function reservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    /**
     * Stock físico total en el almacén: disponible + reservado.
     */
    public function onHandQuantity(): int
    {
        return $this->availableQuantity + $this->reservedQuantity;
    }

    public function unitCost(): Money
    {
        return $this->unitCost;
    }

    public function receivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function goodsReceiptRef(): ?string
    {
        return $this->goodsReceiptRef;
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw InventoryException::because("La cantidad debe ser positiva: {$quantity}.");
        }
    }
}
