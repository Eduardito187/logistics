<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Presentation\Http\Request\RegisterNodeRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Da de alta un nodo desde la puerta de integraciones (sincronización de datos maestros SAP).
 *
 * Despacha el comando al CommandBus; el alta efectiva y sus efectos (eventos vía outbox) ocurren
 * dentro del handler. Responde 202 Accepted: la respuesta no espera a proyecciones externas.
 */
final class RegisterNodeController
{
    public function __invoke(RegisterNodeRequest $request, CommandBus $bus): JsonResponse
    {
        /** @var array{code: string, name: string, type: string, latitude: int|float|string, longitude: int|float|string, department?: string|null} $data */
        $data = $request->validated();

        $department = isset($data['department']) && $data['department'] !== ''
            ? (string) $data['department']
            : null;

        $bus->dispatch(new RegisterNodeCommand(
            $data['code'],
            $data['name'],
            $data['type'],
            (float) $data['latitude'],
            (float) $data['longitude'],
            $department,
        ));

        return new JsonResponse([
            'data' => ['code' => $data['code']],
        ], 202);
    }
}
