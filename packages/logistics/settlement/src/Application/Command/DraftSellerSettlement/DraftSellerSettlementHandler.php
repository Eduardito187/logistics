<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Command\DraftSellerSettlement;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Settlement\Domain\Contract\SellerDispatchReader;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agrega los despachos del seller del periodo (lectura cross-context read-only), arma el borrador de la
 * liquidación aplicando comisión + fee fijo (los fees diferidos quedan en 0), lo persiste y publica sus
 * eventos al outbox. Falla si el seller no tuvo despachos liquidables en el periodo.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DraftSellerSettlement>
 */
final readonly class DraftSellerSettlementHandler implements CommandHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private SellerDispatchReader $dispatches,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DraftSellerSettlement);

        $period = SettlementPeriod::of($command->periodFrom, $command->periodTo);
        $lines = $this->dispatches->dispatchesForSeller($command->sellerId, $period, $command->currency);

        $settlement = SellerSettlement::draft(
            $this->settlements->nextIdentity(),
            $command->sellerId,
            $period,
            $lines,
            $command->commissionBasisPoints,
            Money::of($command->fixedFeeCents, $command->currency),
        );

        $this->settlements->save($settlement);

        $this->recordEvents->record($settlement->releaseEvents(), 'settlement.seller');
    }
}
