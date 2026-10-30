<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\RegisterAccount;

use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\AccountType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Registra la cuenta, la persiste y publica sus eventos al outbox (misma transacción que abre el
 * decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<RegisterAccount>
 */
final readonly class RegisterAccountHandler implements CommandHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterAccount);

        $account = Account::register(
            $this->accounts->nextIdentity(),
            AccountType::from($command->type),
            Uuid::fromString($command->userId),
            $command->displayName,
            $command->taxId,
        );

        $this->accounts->save($account);

        $this->recordEvents->record($account->releaseEvents(), 'accounts.account');
    }
}
