<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Controller;

use Commerce\Accounts\Application\Query\GetAccountByUser\GetAccountByUser;
use Commerce\Accounts\Application\ReadModel\AccountView;
use Commerce\Accounts\Presentation\Http\ApiController;
use Commerce\Accounts\Presentation\Http\Request\RegisterAccountRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: registra una cuenta comercial para un usuario de `access` (comando RegisterAccount).
 *
 * El comando NO acepta id: el handler genera la identidad con nextIdentity. Presentation no puede (ni
 * debe) generar ids de dominio, pero la escritura es síncrona dentro de la transacción del CommandBus,
 * así que el recurso ya existe al volver. Como la cuenta es única por usuario, se re-lee por ese
 * criterio (GetAccountByUser) para devolver el recurso creado con su id (201). Si la re-lectura no lo
 * encuentra (caso límite), se responde 202 con acuse en vez de inventar un id.
 */
final class RegisterAccountController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(RegisterAccountRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $command = $request->toCommand();

            $this->commands->dispatch($command);

            /** @var AccountView|null $account */
            $account = $this->queries->ask(new GetAccountByUser(userId: $command->userId));

            if ($account === null) {
                return $this->accepted([
                    'user_id' => $command->userId,
                    'type' => $command->type,
                ]);
            }

            return $this->data($account->toArray(), 201);
        });
    }
}
