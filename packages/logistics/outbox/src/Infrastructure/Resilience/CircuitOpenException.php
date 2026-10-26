<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Resilience;

use RuntimeException;

/**
 * Se lanza cuando el circuito de un destino externo está ABIERTO: el destino acumuló demasiados fallos
 * seguidos y el breaker corta rápido para no gastar el timeout completo ni frenar al resto del lote.
 *
 * Es una excepción de despacho normal: el relay la captura como cualquier otro fallo y aplica el backoff
 * (reintentará cuando venza la ventana de enfriamiento del circuito). Así un destino caído no bloquea a
 * los destinos sanos (A-5 de la auditoría de performance).
 */
final class CircuitOpenException extends RuntimeException
{
    public static function forDestination(string $destination): self
    {
        return new self("el circuito del destino '{$destination}' está abierto: se omite el intento (fast-fail)");
    }
}
