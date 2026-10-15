<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Magento;

use Logistics\Shipments\Application\Contract\MagentoOrderReader;

/**
 * Stub del lector de Magento: no hay pedidos pendientes. Es el binding por defecto cuando no se
 * configuró la tienda Magento (el piloto puede no incluirla), para que el contexto arranque y se
 * desarrolle sin credenciales. La integración real es HttpMagentoOrderReader.
 */
final class NullMagentoOrderReader implements MagentoOrderReader
{
    public function fetchPendingOrders(): array
    {
        return [];
    }
}
