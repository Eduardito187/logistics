<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Controller;

use Commerce\Promotions\Application\Command\ActivatePromotion\ActivatePromotion;
use Commerce\Promotions\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations: despacha ActivatePromotion al bus CQRS.
 *
 * El id de la promoción viene por la ruta; no hay cuerpo. El bus no devuelve valor (escritura transacción
 * + outbox): la respuesta es un 202 de acuse. Las violaciones de regla de dominio las traduce guard() a 422.
 */
final class ActivatePromotionController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $this->commands->dispatch(new ActivatePromotion(promotionId: $id));

            return $this->accepted(['promotion_id' => $id]);
        });
    }
}
