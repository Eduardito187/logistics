<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Context;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Escucha `QueryExecuted` y registra (canal estructurado) las consultas que superan un umbral
 * (auditoría M-6). Permite detectar N+1, scans y consultas sin índice a escala, sin instrumentar
 * cada llamada a mano.
 *
 * Decisiones:
 *   - Los BINDINGS no se loguean (pueden contener PII: emails, teléfonos, tokens). Se registra el SQL
 *     con sus placeholders `?` y la cantidad de bindings, no sus valores.
 *   - Se arrastra el request-id si está en el contexto de logging (correlación end-to-end, M-7).
 *   - Deshabilitado por defecto: solo activo si una env lo enciende, para no ensuciar los logs de tests
 *     ni el desarrollo normal con cada consulta.
 */
final class SlowQueryListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly float $thresholdMs,
    ) {}

    public function __invoke(QueryExecuted $event): void
    {
        if (!$this->enabled) {
            return;
        }

        // $event->time es la duración en milisegundos (float). Por debajo del umbral no se registra.
        if ($event->time < $this->thresholdMs) {
            return;
        }

        $this->logger->warning('slow query', [
            'sql' => $event->sql,
            'time_ms' => round($event->time, 2),
            'threshold_ms' => $this->thresholdMs,
            'connection' => $event->connectionName,
            // Bindings redactados: solo el conteo, nunca los valores.
            'bindings_count' => count($event->bindings),
            'request_id' => $this->requestId(),
        ]);
    }

    /**
     * Request-id del contexto de logging, si algún middleware lo dejó (correlación end-to-end).
     */
    private function requestId(): ?string
    {
        try {
            $value = Context::get('request_id');

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
