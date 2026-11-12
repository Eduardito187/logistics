<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\RequestSapOrdersImport\RequestSapOrdersImport;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta INTEGRATIONS: pide la lectura de pedidos pendientes en SAP (capa anti-corrupción, ADR 002).
 *
 * Es ASÍNCRONO: el comando solo encola el job ImportSapOrdersJob en la cola `sap` y el request responde
 * 202 de inmediato. SAP nunca se llama dentro del request, así su lentitud o caída no afecta al
 * llamador ni a la plataforma; el worker traduce los pedidos al modelo propio.
 */
final class ImportSapOrdersController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->commandBus->dispatch(new RequestSapOrdersImport);

        return new JsonResponse([
            'data' => [
                'status' => 'IMPORT_REQUESTED',
            ],
            'meta' => [
                'message' => 'SAP order import queued.',
            ],
        ], JsonResponse::HTTP_ACCEPTED);
    }
}
