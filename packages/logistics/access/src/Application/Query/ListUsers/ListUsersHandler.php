<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListUsers;

use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Model\User;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de usuarios como proyecciones de lectura.
 *
 * @implements QueryHandler<ListUsers, list<UserView>>
 */
final readonly class ListUsersHandler implements QueryHandler
{
    public function __construct(
        private UserRepository $users,
    ) {}

    /**
     * @return list<UserView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListUsers);

        $users = $this->users->all();
        if ($query->status !== null) {
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => $user->status()->value === $query->status,
            ));
        }

        return array_map(static fn (User $user): UserView => UserView::fromDomain($user), $users);
    }
}
