<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Controller;

use Commerce\Promotions\Presentation\Http\ApiController;
use Commerce\Promotions\Presentation\Http\Request\CreatePromotionRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations: despacha CreatePromotion al bus CQRS.
 *
 * El comando genera internamente el id de la promoción (nextIdentity) y el bus no devuelve valor: la
 * escritura va envuelta en transacción + outbox. Por eso la respuesta es un 202 de acuse, sin exponer
 * el id generado. Las violaciones de regla de dominio las traduce guard() a 422.
 */
final class CreatePromotionController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(CreatePromotionRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $command = $request->toCommand();

            $this->commands->dispatch($command);

            return $this->accepted([
                'name' => $command->name,
                'type' => $command->type,
                'code' => $command->code,
            ]);
        });
    }
}
