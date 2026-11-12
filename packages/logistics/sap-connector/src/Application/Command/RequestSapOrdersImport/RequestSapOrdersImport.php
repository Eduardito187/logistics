<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\RequestSapOrdersImport;

use Logistics\Shared\Application\Command\Command;

/**
 * Pedido de importación de pedidos desde SAP (puerta integrations). NO llama a SAP dentro del request:
 * solo encola la lectura en la cola `sap` y vuelve de inmediato.
 */
final readonly class RequestSapOrdersImport implements Command {}
