<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\DispatchPendingTransfers;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando del worker de reconciliación: toma traspasos despachables y los empuja a SAP. Corre en el
 * worker de la cola `sap` o en el scheduler, NUNCA en un request de usuario.
 *
 * Parámetros (todos con default para mantener compatibles a los llamadores existentes):
 *  - $includePending: si se incluyen los Pending. El scheduler lo apaga: los traspasos NUEVOS los empuja
 *    solo el reactor del outbox (SapMessageDispatcher), así no hay doble envío.
 *  - $pendingOlderThanMinutes: solo Pending "estancados" (sin tocar hace al menos N minutos), para no
 *    pisar al reactor del outbox con un traspaso recién encolado.
 *  - $failedOlderThanMinutes: backoff de los Failed (último intento hace al menos N minutos).
 *  - $limit: tope del lote (lotes chicos: el HTTP a SAP es lento).
 *  - $timeBudgetSeconds: corta el lote al agotar el presupuesto (0 = sin tope). Lo usa el job de cola
 *    para terminar antes de su timeout; lo que quede lo toma la próxima corrida.
 */
final readonly class DispatchPendingTransfers implements Command
{
    public function __construct(
        public bool $includePending = true,
        public int $pendingOlderThanMinutes = 0,
        public int $failedOlderThanMinutes = 0,
        public int $limit = 100,
        public int $timeBudgetSeconds = 0,
    ) {}
}
