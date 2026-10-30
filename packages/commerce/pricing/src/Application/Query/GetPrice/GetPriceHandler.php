<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Query\GetPrice;

use Commerce\Pricing\Application\ReadModel\PriceQuote;
use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\Sku;
use Commerce\Pricing\Domain\ValueObject\TaxPolicy;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve el precio de un SKU en un canal: busca la lista activa del canal, lee el precio bruto del SKU
 * y lo descompone con la política tributaria de Bolivia (IVA 13 % incluido + IT 3 %).
 *
 * Devuelve null si no hay lista activa para el canal o el SKU no tiene precio fijado en ella.
 *
 * @implements QueryHandler<GetPrice, PriceQuote|null>
 */
final readonly class GetPriceHandler implements QueryHandler
{
    public function __construct(
        private PriceListRepository $priceLists,
    ) {}

    public function handle(Query $query): ?PriceQuote
    {
        assert($query instanceof GetPrice);

        $channel = Channel::from($query->channel);
        $list = $this->priceLists->findActiveByChannel($channel);
        if ($list === null) {
            return null;
        }

        $sku = Sku::of($query->sku);
        $gross = $this->priceLists->findPrice($list->id(), $sku);
        if ($gross === null) {
            return null;
        }

        return PriceQuote::fromGross($sku->value, $channel->value, $gross, TaxPolicy::bolivia());
    }
}
