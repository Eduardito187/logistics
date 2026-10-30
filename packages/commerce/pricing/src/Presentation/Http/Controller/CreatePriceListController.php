<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Controller;

use Commerce\Pricing\Presentation\Http\ApiController;
use Commerce\Pricing\Presentation\Http\Request\CreatePriceListRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations (gestión PIM): despacha CreatePriceList al bus CQRS.
 *
 * El comando genera internamente el id de la lista (nextIdentity) y el bus no devuelve valor: la
 * escritura va envuelta en transacción + outbox. Por eso la respuesta es un 202 de acuse, sin exponer
 * el id generado. Las violaciones de regla de dominio las traduce guard() a 422.
 */
final class CreatePriceListController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(CreatePriceListRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $command = $request->toCommand();

            $this->commands->dispatch($command);

            return $this->accepted([
                'name' => $command->name,
                'channel' => $command->channel,
                'currency' => $command->currency,
            ]);
        });
    }
}
