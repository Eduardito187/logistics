<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Application\Contract;

use Logistics\SellerPortal\Application\ReadModel\SellerAsnView;
use Logistics\SellerPortal\Application\ReadModel\SellerSettlementView;
use Logistics\SellerPortal\Application\ReadModel\SellerStockView;

/**
 * Puerto de lectura de los datos de un seller. Agrega lecturas read-only de otros contextos
 * (`inbound` / `inventory` / `settlement`) ya filtradas por el seller, sin acoplar sus agregados.
 *
 * La implementación lee las tablas de esos contextos directamente (monorepo, autoload común), siempre
 * acotadas por el `sellerId`, para que el portal se mantenga extraíble a microservicio.
 */
interface SellerReadPort
{
    /**
     * ASNs (avisos de envío entrante) del seller.
     *
     * @return list<SellerAsnView>
     */
    public function asns(string $sellerId): array;

    /**
     * Lotes de stock on-hand cuyo dueño es el seller (consignación).
     *
     * @return list<SellerStockView>
     */
    public function stock(string $sellerId): array;

    /**
     * Liquidaciones del seller.
     *
     * @return list<SellerSettlementView>
     */
    public function settlements(string $sellerId): array;
}
