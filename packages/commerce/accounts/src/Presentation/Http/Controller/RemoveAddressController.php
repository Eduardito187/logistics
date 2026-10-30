<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Controller;

use Commerce\Accounts\Application\Command\RemoveAddress\RemoveAddress;
use Commerce\Accounts\Application\Query\GetAccount\GetAccount;
use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: quita una dirección de una cuenta (comando RemoveAddress). No lleva cuerpo.
 *
 * En este dominio la dirección no tiene id propio: se identifica por su etiqueta (`label`) única dentro
 * de la cuenta, así que el segmento {address} de la ruta ES la etiqueta. Tras la escritura síncrona se
 * re-lee la cuenta (read-after-write) y se devuelve actualizada (200); 404 si la cuenta no existe.
 */
final class RemoveAddressController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $account, string $address): JsonResponse
    {
        return $this->guard(function () use ($account, $address): JsonResponse {
            $this->commands->dispatch(new RemoveAddress(
                accountId: $account,
                label: $address,
            ));

            /** @var AccountView|null $view */
            $view = $this->queries->ask(new GetAccount(accountId: $account));

            if ($view === null) {
                return $this->error('account_not_found', 'No account found for the given identifier.', 404);
            }

            return $this->data($view->toArray());
        });
    }
}
