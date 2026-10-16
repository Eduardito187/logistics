<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

/**
 * Eje de un cupo: para qué proceso se reserva la capacidad.
 *
 * El motor de cupos (definir/consumir/liberar) es el mismo para ambos; lo único que cambia es a qué
 * apunta el scope. `dispatch` es el eje histórico (despacho: municipio/zona/nodo); `inbound` es el
 * espejo de recepción (andén de un almacén central que recibe proveedores — ver roadmap marketplace §5).
 *
 * Es el discriminador `scope_kind` de la tabla capacity_buckets; su default es `dispatch` para no tocar
 * los cupos de despacho ya existentes.
 */
enum ScopeKind: string
{
    /** Cupo de DESPACHO (salida): municipio, zona o nodo. Eje histórico. */
    case Dispatch = 'dispatch';

    /** Cupo de RECEPCIÓN (entrada): andén de un almacén central. Eje nuevo (marketplace/inbound). */
    case Inbound = 'inbound';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::Dispatch => 'Dispatch',
            self::Inbound => 'Inbound',
        };
    }
}
