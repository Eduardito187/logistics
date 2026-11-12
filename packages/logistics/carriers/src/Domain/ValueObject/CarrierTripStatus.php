<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

/**
 * Estado del ciclo de vida de un viaje de transportista.
 *
 * El dominio decide las transiciones (ver CarrierTrip): la oficina lo programa (Scheduled) y le asigna
 * envíos; el transportista lo inicia al recoger (InProgress) y lo cierra al entregar la carga (Closed).
 * Un viaje programado que no sale se cancela (Cancelled). El enum solo enumera los estados válidos.
 */
enum CarrierTripStatus: string
{
    /** Programado por la oficina: carga en armado, aún no salió. */
    case Scheduled = 'scheduled';

    /** Iniciado por el transportista: la carga está en ruta. */
    case InProgress = 'in_progress';

    /** Cerrado: la carga se entregó en destino. Terminal. */
    case Closed = 'closed';

    /** Cancelado antes de salir. Terminal. */
    case Cancelled = 'cancelled';

    /**
     * ¿El viaje sigue vigente (programado o en ruta)? Un envío solo puede estar en UN viaje vigente.
     */
    public function isActive(): bool
    {
        return $this === self::Scheduled || $this === self::InProgress;
    }

    /**
     * Valores de los estados vigentes, para filtrar en SQL.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Scheduled->value, self::InProgress->value];
    }

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::InProgress => 'In progress',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }
}
