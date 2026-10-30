<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Controller;

use Commerce\Promotions\Application\ReadModel\DiscountQuote;
use Commerce\Promotions\Presentation\Http\ApiController;
use Commerce\Promotions\Presentation\Http\Request\EvaluatePromotionsRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: evalúa las promociones aplicables a un carrito (consulta EvaluatePromotions).
 *
 * El storefront aplica las promos client-side: envía subtotal/canal/cupón y recibe las promos que
 * aplican, el descuento total y si corresponde envío gratis. Es LECTURA/evaluación (sin efecto): se
 * lee vía QueryBus (EvaluatePromotions → DiscountQuote) y se responde 200 con el resultado.
 */
final class EvaluatePromotionsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(EvaluatePromotionsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var DiscountQuote $quote */
            $quote = $this->queries->ask($request->toQuery());

            return $this->data($quote->toArray());
        });
    }
}
