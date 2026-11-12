<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\RetrySapNotification;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando operativo (acción «Retry» del panel): reinicia los intentos de un aviso a SAP no enviado
 * (Failed/Skipped) y re-encola su envío en la cola `sap`. Opcional: la plataforma no lo necesita.
 */
final readonly class RetrySapNotification implements Command
{
    public function __construct(
        public string $notificationId,
    ) {}
}
