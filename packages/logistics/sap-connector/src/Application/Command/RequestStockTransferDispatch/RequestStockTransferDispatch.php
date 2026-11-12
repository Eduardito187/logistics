<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\RequestStockTransferDispatch;

use Logistics\Shared\Application\Command\Command;

/**
 * Pedido de una corrida de reconciliación de traspasos (endpoint de la puerta app y acción del panel).
 * NO empuja nada a SAP: solo encola el trabajo en la cola `sap` y vuelve de inmediato.
 */
final readonly class RequestStockTransferDispatch implements Command {}
