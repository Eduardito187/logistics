<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordExpense;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\FleetOps\Domain\Model\Expense;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el gasto, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RecordExpense>
 */
final readonly class RecordExpenseHandler implements CommandHandler
{
    public function __construct(
        private ExpenseRepository $expenses,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordExpense);

        $expense = Expense::record(
            $this->expenses->nextIdentity(),
            $command->vehicleId,
            ExpenseCategory::from($command->category),
            Money::of($command->amountCents, $command->currency),
            $command->description,
            new DateTimeImmutable($command->incurredAt),
            $command->note,
        );

        $this->expenses->save($expense);

        $this->recordEvents->record($expense->releaseEvents(), 'fleetops.expense');
    }
}
