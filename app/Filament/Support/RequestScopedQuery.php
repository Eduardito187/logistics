<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Acota la lista de un Resource a su ENTIDAD PADRE cuando llega su id por query string (`?<param>=<id>`).
 *
 * Reemplaza al deep-link `?tableFilters[<f>][value]=X` de Filament, que en esta versión NO hidrata el
 * filtro desde la URL (verificado: abre la lista sin filtrar). Así los enlaces «Gestión de <entidad>» de
 * las fichas 360 (ficha del vehículo → odómetro/mantenimiento/…, del envío → paquetes, del transportista →
 * tarifas/liquidaciones, del vendedor → liquidaciones) abren la lista REALMENTE filtrada al padre, sin
 * tocar el estado de filtros del usuario ni mostrar el id (sigue siendo un parámetro de URL, no texto).
 *
 * Uso: en `table()` del recurso → `->modifyQueryUsing(RequestScopedQuery::by('vehicle', 'vehicle_id'))`,
 * y el enlace apunta a `...getUrl('index').'?vehicle='.rawurlencode($id)`.
 */
final class RequestScopedQuery
{
    /**
     * Callback para `modifyQueryUsing`: si viene `?<param>=<id>` en la URL, acota por `<column> = <id>`.
     *
     * @return Closure(Builder<Model>): Builder<Model>
     */
    public static function by(string $param, string $column): Closure
    {
        return static function (Builder $query) use ($param, $column): Builder {
            /** @var Builder<Model> $query */
            $value = request()->query($param);

            return is_string($value) && $value !== '' ? $query->where($column, $value) : $query;
        };
    }

    /**
     * Varios posibles padres: aplica el where del PRIMER parámetro presente. Útil cuando el recurso cuelga
     * de más de una entidad (p. ej. una tripulación se abre desde el vehículo `?vehicle=` o desde el chofer
     * `?driver=`).
     *
     * @param  array<string, string>                   $paramToColumn param de URL => columna
     * @return Closure(Builder<Model>): Builder<Model>
     */
    public static function byAny(array $paramToColumn): Closure
    {
        return static function (Builder $query) use ($paramToColumn): Builder {
            foreach ($paramToColumn as $param => $column) {
                $value = request()->query($param);
                if (is_string($value) && $value !== '') {
                    return $query->where($column, $value);
                }
            }

            return $query;
        };
    }

    /**
     * Padre que puede estar en VARIAS columnas (OR): acota a las filas donde cualquiera de `$columns`
     * iguala el id (p. ej. un tramo toca un nodo por `from_node_id` O `to_node_id`).
     *
     * @param  list<string>                            $columns
     * @return Closure(Builder<Model>): Builder<Model>
     */
    public static function touching(string $param, array $columns): Closure
    {
        return static function (Builder $query) use ($param, $columns): Builder {
            /** @var Builder<Model> $query */
            $value = request()->query($param);
            if (!is_string($value) || $value === '' || $columns === []) {
                return $query;
            }

            return $query->where(static function (Builder $w) use ($columns, $value): void {
                foreach ($columns as $i => $column) {
                    $i === 0 ? $w->where($column, $value) : $w->orWhere($column, $value);
                }
            });
        };
    }

    /**
     * Dueño POLIMÓRFICO (owner_type/owner_id): acota a `owner_type = $type` y `owner_id = ?<param>` (p. ej.
     * documentos de un chofer: `?driver=<id>` con type 'driver').
     *
     * @return Closure(Builder<Model>): Builder<Model>
     */
    public static function ownedBy(string $param, string $type, string $typeColumn = 'owner_type', string $idColumn = 'owner_id'): Closure
    {
        return static function (Builder $query) use ($param, $type, $typeColumn, $idColumn): Builder {
            /** @var Builder<Model> $query */
            $value = request()->query($param);

            return is_string($value) && $value !== ''
                ? $query->where($typeColumn, $type)->where($idColumn, $value)
                : $query;
        };
    }
}
