<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\AdvanceTracking;

use Logistics\Shared\Application\Command\Command;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Avanza el estado visible del seguimiento al estado dado, respetando la máquina de estados. Objetivo:
 * por `shipmentId` (hechos por envío: carga, salida, entrega) o por `orderReference` (hechos por pedido:
 * pago, etiqueta, cancelación, devolución → se aplican a TODOS los envíos hermanos).
 *
 * Tolerante: si no hay seguimiento, o la transición no aplica desde el estado actual, se saltea (la
 * proyección no se rompe por un evento tardío/duplicado del outbox).
 */
final readonly class AdvanceTracking implements Command
{
    public function __construct(
        public VisibleStatus $status,
        public ?string $shipmentId = null,
        public ?string $orderReference = null,
    ) {}
}
