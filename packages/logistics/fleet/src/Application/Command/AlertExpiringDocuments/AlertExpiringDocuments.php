<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AlertExpiringDocuments;

use Logistics\Shared\Application\Command\Command;

/**
 * Dispara el barrido de documentos de flota por vencer / vencidos y, si hay alguno, emite el evento de
 * alerta al outbox para que notificaciones avise al supervisor.
 *
 * `referenceDate` (Y-m-d) permite fijar la fecha del barrido en tests; si es null, el handler usa hoy
 * (vía Clock). `days` permite forzar la ventana; si es null, se toma de la política configurada
 * (settings con respaldo por config).
 */
final readonly class AlertExpiringDocuments implements Command
{
    public function __construct(
        public ?string $referenceDate = null,
        public ?int $days = null,
    ) {}
}
