<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\RegisterPermission\RegisterPermission;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\RegisterPermissionRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra un permiso nuevo en el catálogo despachando el comando RegisterPermission al bus CQRS.
 */
final class CreatePermissionController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RegisterPermissionRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $description = $request->input('description');

            $this->commands->dispatch(new RegisterPermission(
                key: $request->string('key')->toString(),
                description: is_string($description) && $description !== '' ? $description : null,
                isSystem: $request->boolean('is_system'),
            ));

            return $this->accepted(['key' => $request->string('key')->toString()]);
        });
    }
}
