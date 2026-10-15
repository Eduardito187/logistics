<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Query\GetUser\GetUser;
use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un usuario por su identificador (consulta GetUser). Responde 404 si no existe.
 */
final class GetUserController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $userId): JsonResponse
    {
        return $this->guard(function () use ($userId): JsonResponse {
            /** @var UserView|null $user */
            $user = $this->queries->ask(new GetUser(userId: $userId));

            if ($user === null) {
                return $this->error('user_not_found', 'No user found for the given identifier.', 404);
            }

            return $this->data($user->toArray());
        });
    }
}
