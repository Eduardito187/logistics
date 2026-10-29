<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Query\GetAccount;

use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una cuenta por su id, o null si no existe.
 *
 * @implements QueryHandler<GetAccount, AccountView|null>
 */
final readonly class GetAccountHandler implements QueryHandler
{
    public function __construct(
        private AccountRepository $accounts,
    ) {}

    public function handle(Query $query): ?AccountView
    {
        assert($query instanceof GetAccount);

        $account = $this->accounts->findById(AccountId::fromString($query->accountId));

        return $account !== null ? AccountView::fromDomain($account) : null;
    }
}
