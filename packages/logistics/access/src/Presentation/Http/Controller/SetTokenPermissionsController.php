<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\SetTokenPermissions\SetTokenPermissions;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\SetTokenPermissionsRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reemplaza el conjunto completo de permisos de un token de integración (comando SetTokenPermissions).
 * El id llega por la ruta.
 */
final class SetTokenPermissionsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SetTokenPermissionsRequest $request, string $tokenId): JsonResponse
    {
        return $this->guard(function () use ($request, $tokenId): JsonResponse {
            /** @var list<string> $permissionKeys */
            $permissionKeys = array_values($request->input('permission_keys', []));

            $this->commands->dispatch(new SetTokenPermissions(
                tokenId: $tokenId,
                permissionKeys: $permissionKeys,
            ));

            return $this->accepted(['token_id' => $tokenId]);
        });
    }
}
