<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Controller;

use Commerce\Promotions\Presentation\Http\ApiController;
use Commerce\Promotions\Presentation\Http\Request\ChangePromotionRulesRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations: despacha ChangePromotionRules al bus CQRS.
 *
 * El id de la promoción viene por la ruta; el cuerpo trae las nuevas reglas. El bus no devuelve valor
 * (escritura transacción + outbox): la respuesta es un 202 de acuse. Las violaciones de regla de dominio
 * las traduce guard() a 422.
 */
final class ChangePromotionRulesController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(ChangePromotionRulesRequest $request, string $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id): JsonResponse {
            $this->commands->dispatch($request->toCommand($id));

            return $this->accepted(['promotion_id' => $id]);
        });
    }
}
