<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Controller;

use Commerce\Pricing\Application\Command\ActivatePriceList\ActivatePriceList;
use Commerce\Pricing\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations (gestión PIM): despacha ActivatePriceList al bus CQRS.
 *
 * El id de la lista viene por la ruta; no hay cuerpo. El bus no devuelve valor (escritura transacción +
 * outbox): la respuesta es un 202 de acuse. Las violaciones de regla de dominio las traduce guard() a 422.
 */
final class ActivatePriceListController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $this->commands->dispatch(new ActivatePriceList(priceListId: $id));

            return $this->accepted(['price_list_id' => $id]);
        });
    }
}
