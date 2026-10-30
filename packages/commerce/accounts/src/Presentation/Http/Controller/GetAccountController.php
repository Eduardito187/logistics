<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Controller;

use Commerce\Accounts\Application\Query\GetAccount\GetAccount;
use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: devuelve una cuenta por su id (consulta GetAccount). Responde 404 si no existe.
 */
final class GetAccountController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $account): JsonResponse
    {
        return $this->guard(function () use ($account): JsonResponse {
            /** @var AccountView|null $view */
            $view = $this->queries->ask(new GetAccount(accountId: $account));

            if ($view === null) {
                return $this->error('account_not_found', 'No account found for the given identifier.', 404);
            }

            return $this->data($view->toArray());
        });
    }
}
