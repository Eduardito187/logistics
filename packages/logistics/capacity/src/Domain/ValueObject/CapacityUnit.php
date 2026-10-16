<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

/**
 * Unidad en la que se mide un cupo. Un mismo nodo/zona puede tener buckets en varias unidades a la
 * vez (p. ej. un tope de entregas y otro de peso para la misma franja).
 */
enum CapacityUnit: string
{
    /** Número de entregas (cuenta de envíos/paradas). */
    case Deliveries = 'deliveries';

    /** Peso, en kilogramos. */
    case Weight = 'weight';

    /** Volumen, en metros cúbicos. */
    case Volume = 'volume';

    /**
     * Número de citas de recepción (camiones/turnos de andén). Unidad natural del eje inbound: cuántos
     * ASN caben en una franja de andén de un almacén central.
     */
    case Appointments = 'appointments';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::Deliveries => 'Deliveries',
            self::Weight => 'Weight (kg)',
            self::Volume => 'Volume (m³)',
            self::Appointments => 'Appointments',
        };
    }
}
