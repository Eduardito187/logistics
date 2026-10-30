<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\RemoveAddress;

use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Quita una dirección de una cuenta existente. Idempotente (si la etiqueta no existe, no graba evento).
 *
 * @implements CommandHandler<RemoveAddress>
 */
final readonly class RemoveAddressHandler implements CommandHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RemoveAddress);

        $account = $this->accounts->findById(AccountId::fromString($command->accountId));
        if ($account === null) {
            throw AccountsException::because('La cuenta de la que se quita la dirección no existe.');
        }

        $account->removeAddress($command->label);

        $this->accounts->save($account);

        $this->recordEvents->record($account->releaseEvents(), 'accounts.account');
    }
}
