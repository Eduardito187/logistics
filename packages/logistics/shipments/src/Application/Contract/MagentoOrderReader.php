<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;

/**
 * Lee pedidos pendientes de la tienda Magento actual y los entrega ya normalizados al comando ÚNICO de
 * ingreso de la plataforma (ReceiveShipment). Es un adaptador de fuente (SHP-07).
 *
 * El QUÉ (traer pedidos de Magento como comandos de ingreso) lo define Application; el CÓMO (REST,
 * credenciales, paginación) es de Infrastructure. Si Magento no entra al piloto, se usa un stub que
 * devuelve una lista vacía, sin tocar el dominio ni el caso de uso.
 */
interface MagentoOrderReader
{
    /**
     * @return list<ReceiveShipment>
     */
    public function fetchPendingOrders(): array;
}
