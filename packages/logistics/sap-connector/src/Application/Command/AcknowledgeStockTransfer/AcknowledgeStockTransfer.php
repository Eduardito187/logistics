<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para registrar la confirmación de SAP sobre un traspaso ya empujado. Lo dispara el callback
 * de SAP (puerta integrations). Se identifica por `eventId` (la clave de idempotencia que SAP recibió),
 * no por el id interno, para que el lado SAP no necesite conocer identidades de la plataforma.
 *
 * Idempotente: confirmar dos veces el mismo traspaso no es un error (queda Acknowledged).
 */
final readonly class AcknowledgeStockTransfer implements Command
{
    public function __construct(
        public string $eventId,
    ) {}
}
