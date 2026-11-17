<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\CorrectShipmentVisibleState;

use Logistics\Shared\Application\Command\Command;

/**
 * Corrección MANUAL del estado visible de un seguimiento, por un operador (ola 0). Objetivo: un envío por
 * `shipmentId`, o TODOS los hermanos de un pedido por `orderReference` (al menos uno de los dos).
 *
 * Solo admite estados OPERATIVOS corregibles a mano (procesando, en curso, cargado, en camino, entregado a
 * la transportadora); los estados que gobierna un flujo real —entregado (exige POD), anulado, devuelto y
 * pendiente— NO se corrigen por acá. El avance se aplica tolerante (respeta la máquina de estados) y queda
 * en la bitácora con el motivo y el actor. `notifyCustomer` decide si la corrección se avisa al cliente.
 */
final readonly class CorrectShipmentVisibleState implements Command
{
    public function __construct(
        public ?string $shipmentId,
        public ?string $orderReference,
        public string $targetStatus,
        public string $reason,
        public string $actorId,
        public string $actorName,
        public bool $notifyCustomer = false,
    ) {}
}
