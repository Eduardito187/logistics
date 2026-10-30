<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Controller;

use Commerce\Accounts\Application\Command\AddAddress\AddAddress;
use Commerce\Accounts\Application\Query\GetAccount\GetAccount;
use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Presentation\Http\ApiController;
use Commerce\Accounts\Presentation\Http\Request\AddAddressRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: agrega una dirección de compra/envío a una cuenta (comando AddAddress). El id de la
 * cuenta viaja en la ruta; los campos de la dirección, en el cuerpo. Tras la escritura síncrona se
 * re-lee la cuenta (read-after-write) y se devuelve actualizada (200); 404 si la cuenta no existe.
 */
final class AddAddressController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(AddAddressRequest $request, string $account): JsonResponse
    {
        return $this->guard(function () use ($request, $account): JsonResponse {
            $this->commands->dispatch(new AddAddress(
                accountId: $account,
                label: $request->string('label')->toString(),
                recipient: $request->string('recipient')->toString(),
                line1: $request->string('line1')->toString(),
                city: $request->string('city')->toString(),
                line2: $request->filled('line2') ? $request->string('line2')->toString() : null,
                state: $request->filled('state') ? $request->string('state')->toString() : null,
                phone: $request->filled('phone') ? $request->string('phone')->toString() : null,
                isDefault: $request->boolean('is_default'),
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
