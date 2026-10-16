<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\ReleaseStock;

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
 * Libera una reserva de un lote existente, persiste el cambio, registra el movimiento y publica eventos.
 *
 * @implements CommandHandler<ReleaseStock>
 */
final readonly class ReleaseStockHandler implements CommandHandler
{
    public function __construct(
        private StockLotRepository $lots,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReleaseStock);

        $lot = $this->lots->findById(LotId::fromString($command->lotId));
        if ($lot === null) {
            throw InventoryException::because("No existe el lote {$command->lotId}.");
        }

        $lot->release($command->quantity, $command->reference);

        $this->lots->save($lot);
        $this->lots->appendMovement(new StockMovement(
            $lot->id(),
            $lot->warehouseId(),
            $lot->sku(),
            $lot->ownership(),
            MovementType::Released,
            $command->quantity,
            $command->reference,
            null,
            $this->clock->now(),
        ));

        $this->recordEvents->record($lot->releaseEvents(), 'inventory.stock_lot');
    }
}
