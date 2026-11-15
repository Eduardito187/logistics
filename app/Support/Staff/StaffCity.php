<?php

declare(strict_types=1);

namespace App\Support\Staff;

use Illuminate\Support\Facades\DB;
use Logistics\Network\Domain\ValueObject\Department;

/**
 * Ciudad (departamento de Bolivia) de una persona de la app: `users.city` guarda el CÓDIGO del
 * departamento (`la_paz`, `santa_cruz`, …), el mismo de `network_nodes.department`.
 *
 * El catálogo y el nombre legible viven en un solo lugar, {@see Department} del contexto de red; acá
 * solo se reutilizan desde el anfitrión. Regla de consistencia: si la persona tiene tienda / nodo, su
 * ciudad es el departamento de ese nodo ({@see ofNode()}).
 *
 * Sin estado: no memoriza nada entre llamadas (seguro bajo Octane).
 */
final class StaffCity
{
    /**
     * Códigos válidos de ciudad (los nueve departamentos).
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return Department::codes();
    }

    /** Código normalizado de una ciudad escrita de cualquier forma razonable, o null si no es ninguna. */
    public static function code(?string $value): ?string
    {
        return Department::tryFromText($value)?->value;
    }

    /** Nombre legible de la ciudad (`potosi` → `Potosí`), o null si no hay ciudad o el código no existe. */
    public static function name(?string $code): ?string
    {
        return Department::tryFromText($code)?->label();
    }

    /**
     * Ciudad que le corresponde a quien pertenece a ese nodo: el departamento del nodo. Null si no hay
     * nodo, si el nodo ya no existe o si no tiene departamento cargado.
     */
    public static function ofNode(?string $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        $department = DB::table('network_nodes')->where('id', $nodeId)->value('department');

        return self::code(is_string($department) ? $department : null);
    }
}
