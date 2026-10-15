<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Presentation\Http\Request\SetSettingRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Publica una nueva versión de una clave de configuración (puerta de integraciones).
 *
 * Despacha el comando al CommandBus; el dominio asigna id, número de versión y fecha de vigencia y
 * nunca pisa la versión anterior. Responde 202 Accepted con el eco de lo aceptado; la lectura del
 * valor vigente se hace por el endpoint de consulta.
 */
final class SetSettingController
{
    public function __invoke(SetSettingRequest $request, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new SetSettingCommand(
            key: $request->key(),
            value: $request->value(),
            effectiveFrom: $request->effectiveFrom(),
            createdBy: $request->createdBy(),
        ));

        return new JsonResponse([
            'data' => [
                'accepted' => true,
                'key' => $request->key(),
            ],
        ], 202);
    }
}
