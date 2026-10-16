<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Command\ReceiveStock;

use DateTimeImmutable;
use Logistics\Inventory\Domain\Contract\StockLotRepository;
use Logistics\Inventory\Domain\Model\StockLot;
use Logistics\Inventory\Domain\ValueObject\Money;
use Logistics\Inventory\Domain\ValueObject\MovementType;
use Logistics\Inventory\Domain\ValueObject\StockMovement;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Crea el lote con el dueño correcto, lo persiste, registra el movimiento de ingreso y publica los
 * eventos al outbox (todo dentro de la transacción que abre el CommandBus).
 *
 * @implements CommandHandler<ReceiveStock>
 */
final readonly class ReceiveStockHandler implements CommandHandler
{
    public function __construct(
        private StockLotRepository $lots,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReceiveStock);

        $ownership = Ownership::fromPrimitives(
            $command->fulfillmentMode,
            $command->ownerPartyId,
            $command->sellerSkuRef,
        );
        $receivedAt = $command->receivedAt !== null
            ? new DateTimeImmutable($command->receivedAt)
            : $this->clock->now();

        $lot = StockLot::receive(
            $this->lots->nextIdentity(),
            $command->warehouseId,
            $command->sku,
            $ownership,
            $command->quantity,
            Money::of($command->unitCostCents, $command->currency),
            $receivedAt,
            $command->goodsReceiptRef,
        );

        $this->lots->save($lot);
        $this->lots->appendMovement(new StockMovement(
            $lot->id(),
            $lot->warehouseId(),
            $lot->sku(),
            $ownership,
            MovementType::Received,
            $command->quantity,
            $command->goodsReceiptRef,
            null,
            $this->clock->now(),
        ));

        $this->recordEvents->record($lot->releaseEvents(), 'inventory.stock_lot');
    }
}
