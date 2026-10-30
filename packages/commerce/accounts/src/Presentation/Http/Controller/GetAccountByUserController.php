<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Controller;

use Commerce\Accounts\Application\Query\GetAccountByUser\GetAccountByUser;
use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: devuelve la cuenta asociada a un usuario de `access` (consulta GetAccountByUser).
 * Responde 404 si el usuario no tiene cuenta.
 */
final class GetAccountByUserController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $userId): JsonResponse
    {
        return $this->guard(function () use ($userId): JsonResponse {
            /** @var AccountView|null $view */
            $view = $this->queries->ask(new GetAccountByUser(userId: $userId));

            if ($view === null) {
                return $this->error('account_not_found', 'No account found for the given user.', 404);
            }

            return $this->data($view->toArray());
        });
    }
}
