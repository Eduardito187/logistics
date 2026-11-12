<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Motivo declarado por el operador del nodo para un producto esperado que no se recibió al conciliar.
 *
 * Es un dato de la conciliación (no cambia el cálculo de faltantes, que sale de los escaneos): explica
 * por qué falta, para la conciliación operativa y los reportes.
 */
enum MissingUnitReason: string
{
    /** No llegó: no vino en el camión / transporte. */
    case NotArrived = 'not_arrived';

    /** Llegó dañado y no se aceptó en el nodo. */
    case Damaged = 'damaged';

    /** Se envió a otra tienda / nodo por error. */
    case WrongStore = 'wrong_store';

    /** Otro motivo (se detalla en las notas de la conciliación). */
    case Other = 'other';

    /**
     * Valores aceptados (para la validación HTTP y el contrato OpenAPI).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
