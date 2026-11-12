<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Notification;

/**
 * Política vigente de los avisos a SAP, armada por el provider desde la config en CADA resolución (no es
 * singleton): así un cambio hecho desde el panel de Configuración se ve sin reiniciar nada más que la
 * relectura de config. Application no lee config(): recibe esta política ya resuelta.
 */
final readonly class SapNotificationPolicy
{
    /** @var list<string> */
    public array $events;

    /** @var array<int, int> */
    public array $backoffMinutes;

    /**
     * @param list<string>    $events         Nombres de evento que se avisan a SAP.
     * @param array<int, int> $backoffMinutes Minutos de espera por intento ya realizado.
     */
    public function __construct(
        public bool $deliveryEnabled,
        array $events,
        public int $maxAttempts = 3,
        array $backoffMinutes = [1 => 1, 2 => 5],
        public int $graceSeconds = 120,
        public int $retryBatch = 50,
        public int $requeueLeaseSeconds = 300,
    ) {
        $this->events = array_values(array_unique($events));
        $this->backoffMinutes = $backoffMinutes;
    }

    /**
     * ¿Este evento se le avisa a SAP?
     */
    public function notifies(string $eventName): bool
    {
        return in_array($eventName, $this->events, true);
    }

    /**
     * Intentos totales efectivos (al menos 1).
     */
    public function attempts(): int
    {
        return max(1, $this->maxAttempts);
    }
}
