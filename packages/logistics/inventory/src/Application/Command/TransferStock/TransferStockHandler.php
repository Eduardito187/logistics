<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\TransferStock;

use Logistics\Inventory\Domain\Contract\StockLotRepository;
use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Inventory\Domain\Model\StockLot;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\MovementType;
use Logistics\Inventory\Domain\ValueObject\StockMovement;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Transfiere stock entre almacenes: resta del lote origen y abre un lote destino con el MISMO dueño.
 *
 * El lote destino se crea con `openFromTransfer` (sin grabar StockReceived): el único hecho es el
 * StockTransferred del origen, para no contar la transferencia como un ingreso nuevo. Ambos lados quedan
 * registrados en el ledger de movimientos.
 *
 * @implements CommandHandler<TransferStock>
 */
final readonly class TransferStockHandler implements CommandHandler
{
    public function __construct(
        private StockLotRepository $lots,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof TransferStock);

        $source = $this->lots->findById(LotId::fromString($command->lotId));
        if ($source === null) {
            throw InventoryException::because("No existe el lote {$command->lotId}.");
        }

        $source->transfer($command->quantity, $command->destinationWarehouseId, $command->reference);
        $this->lots->save($source);

        $now = $this->clock->now();
        $destinationLot = StockLot::openFromTransfer(
            $this->lots->nextIdentity(),
            $command->destinationWarehouseId,
            $source->sku(),
            $source->ownership(),
            $command->quantity,
            $source->unitCost(),
            $now,
            $source->goodsReceiptRef(),
        );
        $this->lots->save($destinationLot);

        // Asiento de salida del origen y de entrada del destino (ambos tipo Transferred).
        $this->lots->appendMovement(new StockMovement(
            $source->id(),
            $source->warehouseId(),
            $source->sku(),
            $source->ownership(),
            MovementType::Transferred,
            $command->quantity,
            $command->reference,
            $command->destinationWarehouseId,
            $now,
        ));
        $this->lots->appendMovement(new StockMovement(
            $destinationLot->id(),
            $destinationLot->warehouseId(),
            $destinationLot->sku(),
            $destinationLot->ownership(),
            MovementType::Transferred,
            $command->quantity,
            $command->reference,
            $source->warehouseId(),
            $now,
        ));

        $this->recordEvents->record($source->releaseEvents(), 'inventory.stock_lot');
    }
}
