<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\SubmitSettlement;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Domain\ValueObject\BillingPeriod;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Valida que el transportista exista, construye la liquidación, la persiste y publica sus eventos al
 * outbox. Falla si el transportista no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SubmitSettlement>
 */
final readonly class SubmitSettlementHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
        private SettlementRepository $settlements,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SubmitSettlement);

        $carrier = $this->carriers->findById(CarrierId::fromString($command->carrierId));
        if ($carrier === null) {
            throw CarriersException::because("No existe el transportista {$command->carrierId}.");
        }

        $settlement = Settlement::submit(
            $this->settlements->nextIdentity(),
            $command->carrierId,
            CorridorRef::of($command->corridorFrom, $command->corridorTo, $command->corridorType),
            BillingPeriod::of($command->period),
            Money::of($command->amountCents, $command->currency),
            $command->reference,
            $command->documents,
        );

        $this->settlements->save($settlement);

        $this->recordEvents->record($settlement->releaseEvents(), 'carriers.settlement');
    }
}
