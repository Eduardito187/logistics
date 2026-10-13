<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\DispatchPendingTransfers;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando del worker de despacho: toma los traspasos pendientes y los empuja a SAP. No lleva datos;
 * su efecto es sobre todo lo que esté Pending/Failed. Corre en el relay/worker, no en un request.
 */
final readonly class DispatchPendingTransfers implements Command {}
