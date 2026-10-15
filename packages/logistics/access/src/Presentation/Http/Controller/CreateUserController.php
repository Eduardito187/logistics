<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\RegisterUser\RegisterUser;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\RegisterUserRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra un usuario nuevo despachando el comando RegisterUser al bus CQRS.
 */
final class CreateUserController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RegisterUserRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<string> $roleKeys */
            $roleKeys = array_values($request->input('role_keys', []));
            $status = $request->input('status');

            $this->commands->dispatch(new RegisterUser(
                name: $request->string('name')->toString(),
                email: $request->string('email')->toString(),
                plainPassword: $request->string('password')->toString(),
                roleKeys: $roleKeys,
                status: is_string($status) && $status !== '' ? $status : 'active',
            ));

            return $this->accepted(['email' => $request->string('email')->toString()]);
        });
    }
}
