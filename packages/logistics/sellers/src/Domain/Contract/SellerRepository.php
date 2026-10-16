<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Contract;

use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;

/**
 * Puerto de persistencia de sellers. La implementación vive en Infrastructure.
 *
 * El catálogo de SKUs se persiste aparte (puede ser de gran volumen): `saveSku` hace upsert de un SKU y
 * `listSkus` lo lee; el agregado Seller no carga el catálogo completo.
 */
interface SellerRepository
{
    public function nextIdentity(): SellerId;

    /**
     * Upsert del seller por id (incluye sus términos de liquidación, si los tiene).
     */
    public function save(Seller $seller): void;

    public function findById(SellerId $id): ?Seller;

    /**
     * @return list<Seller>
     */
    public function all(): array;

    /**
     * Upsert de un SKU del catálogo del seller (clave natural: seller_id + sku).
     */
    public function saveSku(SellerId $sellerId, SellerSku $sku): void;

    /**
     * @return list<SellerSku>
     */
    public function listSkus(SellerId $sellerId): array;
}
