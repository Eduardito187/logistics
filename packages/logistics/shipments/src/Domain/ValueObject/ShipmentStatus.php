<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Estado del envío a lo largo de su ciclo de vida. Este contexto solo produce los primeros estados
 * (recepción y planificación); la ejecución real (carga, ruta, entrega) la gobiernan otros contextos.
 */
enum ShipmentStatus: string
{
    /** Recién ingresado y normalizado; todavía sin plan de reparto. */
    case Received = 'RECEIVED';

    /** Asignado a un camión y una secuencia por el contexto de planificación. */
    case Planned = 'PLANNED';

    /** En ruta / en preparación o carga. */
    case InExecution = 'IN_EXECUTION';

    /** Entregado al cliente con prueba de entrega. */
    case Delivered = 'DELIVERED';

    /** Intento de entrega fallido (ausente, rechazo, dirección incorrecta…). */
    case Failed = 'FAILED';

    /** Anulado antes de completarse. */
    case Cancelled = 'CANCELLED';

    /**
     * Normaliza un valor de estado venido de afuera (query string, integraciones) sin importar
     * mayúsculas/minúsculas ni espacios: `planned`, ` Planned ` y `PLANNED` resuelven al mismo caso.
     * Devuelve null si el valor no es un estado conocido.
     */
    public static function tryFromAnyCase(string $value): ?self
    {
        return self::tryFrom(strtoupper(trim($value)));
    }

    /**
     * Posición del estado en el AVANCE normal del envío (recibido → planificado → en ejecución →
     * entregado). Sirve para que el avance sea MONÓTONO: nunca se retrocede a un rango menor.
     * Fallido y anulado no forman parte del avance (son desenlaces cerrados): devuelven null.
     */
    public function progressRank(): ?int
    {
        return match ($this) {
            self::Received => 0,
            self::Planned => 1,
            self::InExecution => 2,
            self::Delivered => 3,
            self::Failed, self::Cancelled => null,
        };
    }

    /**
     * Estado cerrado: entregado, fallido o anulado. Un envío cerrado no avanza más por reacción a hechos
     * de planificación/ejecución (las devoluciones y reprogramaciones tienen sus propios flujos).
     */
    public function isClosed(): bool
    {
        return $this === self::Delivered || $this === self::Failed || $this === self::Cancelled;
    }
}
