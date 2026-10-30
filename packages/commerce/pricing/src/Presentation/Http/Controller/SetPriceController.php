<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Controller;

use Commerce\Pricing\Presentation\Http\ApiController;
use Commerce\Pricing\Presentation\Http\Request\SetPriceRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations (gestión PIM): despacha SetPrice al bus CQRS.
 *
 * El id de la lista viene por la ruta; el cuerpo trae el SKU y el precio bruto en centavos. El bus no
 * devuelve valor (escritura transacción + outbox): la respuesta es un 202 de acuse. Las violaciones de
 * regla de dominio (lista inexistente, archivada, moneda distinta) las traduce guard() a 422.
 */
final class SetPriceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SetPriceRequest $request, string $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id): JsonResponse {
            $command = $request->toCommand($id);

            $this->commands->dispatch($command);

            return $this->accepted([
                'price_list_id' => $command->priceListId,
                'sku' => $command->sku,
                'gross_amount_cents' => $command->grossAmountCents,
                'currency' => $command->currency,
            ]);
        });
    }
}
