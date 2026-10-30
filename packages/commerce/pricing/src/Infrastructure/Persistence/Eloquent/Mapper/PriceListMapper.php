<?php

declare(strict_types=1);

namespace Commerce\Pricing\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Pricing\Domain\Model\PriceList;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Domain\ValueObject\PriceListStatus;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListRecord;

/**
 * Traduce entre el agregado PriceList (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class PriceListMapper
{
    public function toDomain(PriceListRecord $record): PriceList
    {
        return PriceList::reconstitute(
            PriceListId::fromString($record->id),
            $record->name,
            $record->currency,
            Channel::from($record->channel),
            PriceListStatus::from($record->status),
        );
    }

    /**
     * Fila de la tabla `commerce_price_lists`.
     *
     * @return array{id: string, name: string, currency: string, channel: string, status: string}
     */
    public function toRow(PriceList $list): array
    {
        return [
            'id' => $list->id()->toString(),
            'name' => $list->name(),
            'currency' => $list->currency(),
            'channel' => $list->channel()->value,
            'status' => $list->status()->value,
        ];
    }
}
