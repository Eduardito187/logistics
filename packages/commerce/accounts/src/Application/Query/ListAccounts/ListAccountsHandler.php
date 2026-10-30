<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Query\ListAccounts;

use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\AccountType;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista cuentas (todas o filtradas por tipo) como ReadModels.
 *
 * @implements QueryHandler<ListAccounts, list<AccountView>>
 */
final readonly class ListAccountsHandler implements QueryHandler
{
    public function __construct(
        private AccountRepository $accounts,
    ) {}

    /**
     * @return list<AccountView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListAccounts);

        $accounts = $this->accounts->all();
        if ($query->type !== null) {
            $type = AccountType::from($query->type);
            $accounts = array_values(array_filter(
                $accounts,
                static fn (Account $account): bool => $account->type() === $type,
            ));
        }

        return array_map(
            static fn (Account $account): AccountView => AccountView::fromDomain($account),
            $accounts,
        );
    }
}
