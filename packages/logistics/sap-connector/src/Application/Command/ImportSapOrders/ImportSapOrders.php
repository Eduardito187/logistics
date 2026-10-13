<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\ImportSapOrders;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando de lectura de pedidos desde SAP. Sin parámetros: lee lo que SAP tenga pendiente. El handler
 * traduce cada pedido al DTO propio (ImportedOrderDto) y lo deja disponible para alimentar al contexto
 * de envíos (sin acoplar a ese package: solo se devuelven DTOs).
 */
final readonly class ImportSapOrders implements Command {}
