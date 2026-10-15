<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\GetUser;

use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un usuario como proyección de lectura, o null si no existe.
 *
 * @implements QueryHandler<GetUser, UserView|null>
 */
final readonly class GetUserHandler implements QueryHandler
{
    public function __construct(
        private UserRepository $users,
    ) {}

    public function handle(Query $query): ?UserView
    {
        assert($query instanceof GetUser);

        $user = $this->users->findById(UserId::fromString($query->userId));

        return $user === null ? null : UserView::fromDomain($user);
    }
}
