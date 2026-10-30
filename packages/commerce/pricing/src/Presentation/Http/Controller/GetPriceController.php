<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Controller;

use Commerce\Pricing\Application\Query\GetPrice\GetPrice;
use Commerce\Pricing\Application\ReadModel\PriceQuote;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: resuelve el precio vigente de un SKU (consulta GetPrice). 404 si no hay precio.
 *
 * Lee vía QueryBus (GetPrice → PriceQuote|null). El canal determina qué lista activa se consulta; por
 * tratarse del storefront (B2C) el canal por defecto es `retail`, pero se puede pedir otro canal válido.
 * La validación es inline por ser un filtro simple de query string.
 */
final class GetPriceController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $channels = array_map(
                static fn (Channel $channel): string => $channel->value,
                Channel::cases(),
            );

            /** @var array{sku: string, channel?: string|null} $params */
            $params = $request->validate([
                'sku' => ['required', 'string', 'max:255'],
                'channel' => ['nullable', 'string', Rule::in($channels)],
            ]);

            /** @var PriceQuote|null $quote */
            $quote = $this->queries->ask(new GetPrice(
                sku: $params['sku'],
                channel: $params['channel'] ?? Channel::Retail->value,
            ));

            if ($quote === null) {
                return $this->error('not_found', 'No price found for the given SKU and channel.', 404);
            }

            return $this->data($quote->toArray());
        });
    }
}
