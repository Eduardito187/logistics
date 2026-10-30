<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\SuspendAccount;

use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Suspende una cuenta existente. Idempotente (el agregado no re-graba si ya está suspendida).
 *
 * @implements CommandHandler<SuspendAccount>
 */
final readonly class SuspendAccountHandler implements CommandHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SuspendAccount);

        $account = $this->accounts->findById(AccountId::fromString($command->accountId));
        if ($account === null) {
            throw AccountsException::because('La cuenta a suspender no existe.');
        }

        $account->suspend();

        $this->accounts->save($account);

        $this->recordEvents->record($account->releaseEvents(), 'accounts.account');
    }
}
