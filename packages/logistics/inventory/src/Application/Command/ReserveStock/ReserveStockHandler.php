<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\ReserveStock;

use Logistics\Inventory\Domain\Contract\StockLotRepository;
use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\MovementType;
use Logistics\Inventory\Domain\ValueObject\StockMovement;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reserva stock de un lote existente, persiste el cambio, registra el movimiento y publica los eventos.
 *
 * @implements CommandHandler<ReserveStock>
 */
final readonly class ReserveStockHandler implements CommandHandler
{
    public function __construct(
        private StockLotRepository $lots,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReserveStock);

        $lot = $this->lots->findById(LotId::fromString($command->lotId));
        if ($lot === null) {
            throw InventoryException::because("No existe el lote {$command->lotId}.");
        }

        $lot->reserve($command->quantity, $command->reference);

        $this->lots->save($lot);
        $this->lots->appendMovement(new StockMovement(
            $lot->id(),
            $lot->warehouseId(),
            $lot->sku(),
            $lot->ownership(),
            MovementType::Reserved,
            $command->quantity,
            $command->reference,
            null,
            $this->clock->now(),
        ));

        $this->recordEvents->record($lot->releaseEvents(), 'inventory.stock_lot');
    }
}
