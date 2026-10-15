<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Query\ListUsers\ListUsers;
use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los usuarios como proyecciones de lectura, con filtro opcional por estado (consulta ListUsers).
 */
final class ListUsersController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var list<UserView> $users */
            $users = $this->queries->ask(new ListUsers(status: $status));

            return $this->data(
                array_map(static fn (UserView $view): array => $view->toArray(), $users),
            );
        });
    }
}
