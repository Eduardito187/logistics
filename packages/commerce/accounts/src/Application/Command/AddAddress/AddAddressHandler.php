<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\AddAddress;

use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Commerce\Accounts\Domain\ValueObject\Address;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agrega una dirección a una cuenta existente. Si viene como predeterminada, desmarca a las demás.
 *
 * @implements CommandHandler<AddAddress>
 */
final readonly class AddAddressHandler implements CommandHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AddAddress);

        $account = $this->accounts->findById(AccountId::fromString($command->accountId));
        if ($account === null) {
            throw AccountsException::because('La cuenta a la que se agrega la dirección no existe.');
        }

        $account->addAddress(Address::of(
            $command->label,
            $command->recipient,
            $command->line1,
            $command->line2,
            $command->city,
            $command->state,
            $command->phone,
            $command->isDefault,
        ));

        $this->accounts->save($account);

        $this->recordEvents->record($account->releaseEvents(), 'accounts.account');
    }
}
