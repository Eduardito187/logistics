<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListUsers;

use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Model\User;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve la lista de usuarios como proyecciones de lectura (ordenada por nombre, como el repositorio).
 *
 * Los filtros y el recorte de página se aplican en memoria sobre `UserRepository::all()`: la cantidad de
 * usuarios internos es acotada (cientos), así que no justifica todavía un método de lectura paginado en
 * SQL. Si crece, el siguiente paso es bajar `limit/offset` al repositorio sin cambiar este contrato.
 *
 * @implements QueryHandler<ListUsers, list<UserView>|Page<UserView>>
 */
final readonly class ListUsersHandler implements QueryHandler
{
    public function __construct(
        private UserRepository $users,
    ) {}

    /**
     * @return list<UserView>|Page<UserView>
     */
    public function handle(Query $query): array|Page
    {
        assert($query instanceof ListUsers);

        $users = $this->users->all();

        if ($query->status !== null) {
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => $user->status()->value === $query->status,
            ));
        }

        if ($query->role !== null) {
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => \in_array($query->role, $user->roleKeys(), true),
            ));
        }

        $needle = $query->search !== null ? mb_strtolower(trim($query->search)) : '';
        if ($needle !== '') {
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => str_contains(mb_strtolower($user->name()), $needle)
                    || str_contains(mb_strtolower($user->email()->value), $needle),
            ));
        }

        $toView = static fn (User $user): UserView => UserView::fromDomain($user);

        // Sin página pedida: comportamiento histórico (lista completa del filtro).
        if ($query->limit === null) {
            return array_map($toView, $users);
        }

        $slice = \array_slice($users, max(0, $query->offset), max(1, $query->limit));

        return new Page(array_map($toView, $slice), \count($users));
    }
}
