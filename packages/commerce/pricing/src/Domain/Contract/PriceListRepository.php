<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Contract;

use Commerce\Pricing\Domain\Model\PriceList;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Domain\ValueObject\Sku;

/**
 * Puerto de persistencia de listas de precios. La implementación vive en Infrastructure.
 *
 * Los precios (ítems) se persisten aparte (pueden ser de gran volumen): `setPrice` hace upsert del
 * precio de un SKU y `listPrices`/`findPrice` lo leen; el agregado PriceList no carga todos los precios.
 */
interface PriceListRepository
{
    public function nextIdentity(): PriceListId;

    /**
     * Upsert de la lista de precios por id (cabecera: nombre, moneda, canal, estado).
     */
    public function save(PriceList $list): void;

    public function findById(PriceListId $id): ?PriceList;

    /**
     * @return list<PriceList>
     */
    public function all(): array;

    /**
     * Lista activa de un canal, o null si no hay ninguna activa para ese canal.
     */
    public function findActiveByChannel(Channel $channel): ?PriceList;

    /**
     * Upsert del precio al público —IVA incluido— de un SKU en una lista (clave natural: price_list_id + sku).
     */
    public function setPrice(PriceListId $listId, Sku $sku, Money $grossAmount): void;

    /**
     * Precio al público de un SKU en una lista, o null si no está fijado. Lookup puntual para GetPrice
     * (no trae toda la lista; a escala el catálogo de precios puede ser de millones de filas).
     */
    public function findPrice(PriceListId $listId, Sku $sku): ?Money;

    /**
     * @return list<array{sku: string, gross_amount: Money}>
     */
    public function listPrices(PriceListId $listId): array;
}
