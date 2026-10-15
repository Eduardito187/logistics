<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se RE-OPTIMIZÓ la ruta de un plan (R-05): ante un cambio del día (nuevo pedido, cancelación o incidente)
 * se re-secuenció la ruta afectada sin rehacer el día entero. Es el hecho que, vía outbox, permite avisar
 * a ejecución/tracking que la hoja de ruta de ese camión cambió (nuevo orden/ETA) sin acoplar contextos.
 *
 * `reason` describe el disparador (p. ej. 'new_order', 'cancellation', 'incident', 'manual').
 */
final readonly class RouteReoptimized extends BaseDomainEvent
{
    public function __construct(
        string $planId,
        public string $planDate,
        public string $vehicleId,
        public int $stopCount,
        public string $reason,
    ) {
        parent::__construct($planId);
    }

    public function eventName(): string
    {
        return 'planning.route_reoptimized';
    }

    public function payload(): array
    {
        return [
            'plan_id' => $this->aggregateId,
            'plan_date' => $this->planDate,
            'vehicle_id' => $this->vehicleId,
            'stop_count' => $this->stopCount,
            'reason' => $this->reason,
        ];
    }
}
