<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Ingest;

/**
 * Resultado de UNA pasada de drenado de un fragmento del buffer de ingesta.
 */
final readonly class FlushReport
{
    /**
     * @param int          $batches        lotes del buffer tomados en la pasada
     * @param int          $groups         grupos por vehículo en que se consolidaron
     * @param int          $pings          lecturas entregadas a la ingesta
     * @param int          $failedGroups   grupos cuya ingesta falló
     * @param int          $retriedBatches lotes devueltos al buffer para reintentar
     * @param int          $buriedBatches  lotes apartados a descartes (agotaron reintentos)
     * @param list<string> $errors         motivo de cada grupo fallido (para el log del adaptador)
     */
    public function __construct(
        public int $batches = 0,
        public int $groups = 0,
        public int $pings = 0,
        public int $failedGroups = 0,
        public int $retriedBatches = 0,
        public int $buriedBatches = 0,
        public array $errors = [],
    ) {}

    /** No había nada que drenar. */
    public function isEmpty(): bool
    {
        return $this->batches === 0;
    }

    /**
     * Falló la pasada ENTERA (típico de la base caída): quien drena debe esperar antes de reintentar en
     * vez de girar en vacío.
     */
    public function stalled(): bool
    {
        return $this->groups > 0 && $this->failedGroups === $this->groups;
    }
}
