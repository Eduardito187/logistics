<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Query\GetAccountByUser;

use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Domain\Contract\AccountRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Devuelve la cuenta asociada a un usuario de `access`, o null si no existe.
 *
 * @implements QueryHandler<GetAccountByUser, AccountView|null>
 */
final readonly class GetAccountByUserHandler implements QueryHandler
{
    public function __construct(
        private AccountRepository $accounts,
    ) {}

    public function handle(Query $query): ?AccountView
    {
        assert($query instanceof GetAccountByUser);

        $account = $this->accounts->findByUserId(Uuid::fromString($query->userId));

        return $account !== null ? AccountView::fromDomain($account) : null;
    }
}
