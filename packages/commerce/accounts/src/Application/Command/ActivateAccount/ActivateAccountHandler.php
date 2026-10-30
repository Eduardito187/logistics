<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\ActivateAccount;

use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Reactiva una cuenta existente. Idempotente (el agregado no re-graba si ya está activa).
 *
 * @implements CommandHandler<ActivateAccount>
 */
final readonly class ActivateAccountHandler implements CommandHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ActivateAccount);

        $account = $this->accounts->findById(AccountId::fromString($command->accountId));
        if ($account === null) {
            throw AccountsException::because('La cuenta a reactivar no existe.');
        }

        $account->activate();

        $this->accounts->save($account);

        $this->recordEvents->record($account->releaseEvents(), 'accounts.account');
    }
}
