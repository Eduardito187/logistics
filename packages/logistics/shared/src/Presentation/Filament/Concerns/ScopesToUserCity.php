<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Filament\Concerns;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * ALCANCE POR CIUDAD en el admin web: acota los listados y las fichas a la ciudad (departamento) del usuario
 * autenticado, con la MISMA regla que la puerta app aplica a los envíos.
 *
 * Quién queda acotado: el planificador y el preparador (`users.city` = código de departamento: `la_paz`,
 * `santa_cruz`, …). Administrador y supervisor nunca: para ellos cada ayudante vuelve sin tocar la consulta
 * (ni un predicado ni una consulta extra). Un usuario acotado SIN ciudad tampoco queda restringido (misma
 * decisión que la puerta app), salvo con `logistics-shipments.scope.strict_without_city` en true: ahí no ve
 * nada hasta que le asignen ciudad.
 *
 * La regla: «el envío es de mi ciudad» = su departamento de ORIGEN o el de DESTINO es el mío. Un envío que
 * todavía no tiene NINGUNO de los dos resueltos se muestra igual (no se esconde un pedido por un dato
 * faltante), igual que en la puerta app.
 *
 * Cómo se usa: en `getEloquentQuery()` del Resource (cubre lista, ficha, búsqueda global y enlaces directos:
 * la ficha de otra ciudad responde 404) y en las consultas de las páginas propias.
 *
 * Nota de arquitectura: este núcleo NO depende del contexto de envíos. Lee por nombre de tabla y de columna
 * (`shipments.origin_department` / `destination_department`, `fleet_vehicles.home_base_node_id`,
 * `network_nodes.department`) y por `config()`, como el resto de las lecturas del admin. Los predicados son
 * igualdades sobre columnas indexadas y EXISTS correlacionados por clave: nunca se traen ids a PHP. No guarda
 * nada en estáticas (los roles ya quedan cargados en el propio usuario durante el request).
 */
trait ScopesToUserCity
{
    /**
     * Departamento al que queda acotado el usuario autenticado:
     *  - null → sin restricción (administrador, supervisor, otro rol, o usuario acotado sin ciudad);
     *  - ''   → acotado y sin ciudad en modo estricto: no ve nada;
     *  - otro → código del departamento (`la_paz`, `santa_cruz`, …).
     */
    protected static function userCityScope(): ?string
    {
        $user = Auth::user();
        if ($user === null || !method_exists($user, 'roles')) {
            return null;
        }

        $roles = [];
        foreach ($user->roles() as $role) {
            if ($role instanceof BackedEnum) {
                $roles[] = strtolower(trim((string) $role->value));
            }
        }

        // La oficina manda: quien además es administrador o supervisor no queda acotado.
        if (array_intersect($roles, ['admin', 'supervisor']) !== []) {
            return null;
        }

        if (array_intersect($roles, ['preparador', 'planificador']) === []) {
            return null;
        }

        // Lectura tolerante: si la columna todavía no existe en este entorno, es «sin ciudad».
        $city = $user instanceof Model ? ($user->getAttributes()['city'] ?? null) : null;
        $department = self::userCitySlug(is_string($city) ? $city : null);
        if ($department !== null) {
            return $department;
        }

        return (bool) config('logistics-shipments.scope.strict_without_city', false) ? '' : null;
    }

    /**
     * La consulta lee la tabla de ENVÍOS (o un alias suyo): deja solo los envíos de la ciudad del usuario.
     *
     * @template TModel of Model
     *
     * @param EloquentBuilder<TModel> | QueryBuilder $query
     * @param string                                 $table tabla o alias con el que la consulta lee los envíos
     */
    protected static function scopeShipmentsToUserCity(EloquentBuilder | QueryBuilder $query, string $table = 'shipments'): void
    {
        $department = self::userCityScope();
        if ($department === null) {
            return;
        }

        $base = self::userCityBaseQuery($query);
        if ($department === '') {
            $base->whereRaw('1 = 0');

            return;
        }

        self::whereShipmentOfCity($base, $department, $table.'.');
    }

    /**
     * La fila referencia UN envío (`<tabla>.shipment_id`): deja solo las filas cuyo envío es de la ciudad
     * del usuario. EXISTS correlacionado por la clave primaria del envío.
     *
     * @template TModel of Model
     *
     * @param EloquentBuilder<TModel> | QueryBuilder $query
     * @param string                                 $shipmentColumn columna calificada con el id del envío
     */
    protected static function scopeToUserCityByShipment(EloquentBuilder | QueryBuilder $query, string $shipmentColumn): void
    {
        $department = self::userCityScope();
        if ($department === null) {
            return;
        }

        $base = self::userCityBaseQuery($query);
        if ($department === '') {
            $base->whereRaw('1 = 0');

            return;
        }

        $base->whereExists(self::shipmentOfCity($department, 'id', $shipmentColumn));
    }

    /**
     * Como {@see scopeToUserCityByShipment()}, para filas que pueden no tener todavía el envío ligado y se
     * identifican por la referencia de la orden (el seguimiento nace con la orden, antes que el envío): la
     * fila entra si su envío es de la ciudad o, cuando no tiene envío, si lo es algún envío de esa orden.
     *
     * @template TModel of Model
     *
     * @param EloquentBuilder<TModel> | QueryBuilder $query
     * @param string                                 $shipmentColumn       columna calificada con el id del envío (nullable)
     * @param string                                 $orderReferenceColumn columna calificada con la referencia de la orden
     */
    protected static function scopeToUserCityByShipmentOrOrder(
        EloquentBuilder | QueryBuilder $query,
        string $shipmentColumn,
        string $orderReferenceColumn,
    ): void {
        $department = self::userCityScope();
        if ($department === null) {
            return;
        }

        $base = self::userCityBaseQuery($query);
        if ($department === '') {
            $base->whereRaw('1 = 0');

            return;
        }

        $base->where(static function (QueryBuilder $where) use ($department, $shipmentColumn, $orderReferenceColumn): void {
            $where->whereExists(self::shipmentOfCity($department, 'id', $shipmentColumn))
                ->orWhere(static function (QueryBuilder $unbound) use ($department, $shipmentColumn, $orderReferenceColumn): void {
                    $unbound->whereNull($shipmentColumn)
                        ->whereExists(self::shipmentOfCity($department, 'order_reference', $orderReferenceColumn));
                });
        });
    }

    /**
     * La fila es un VIAJE de un camión con paradas (un plan de reparto, una ruta del chofer): entra si el
     * camión tiene su base en la ciudad del usuario, o si alguna de sus paradas lleva un envío de la ciudad
     * (así quien ve un envío también ve el viaje que lo lleva, y un viaje todavía vacío es de la ciudad de
     * su camión).
     *
     * @template TModel of Model
     *
     * @param EloquentBuilder<TModel> | QueryBuilder $query
     * @param string                                 $ownerKey      columna calificada con el id del viaje (`planning_plans.id`)
     * @param string                                 $vehicleColumn columna calificada con el id del camión del viaje
     * @param string                                 $stopsTable    tabla de las paradas (`planning_plan_stops`)
     * @param string                                 $stopsOwnerKey columna de la parada que apunta al viaje (`plan_id`)
     */
    protected static function scopeTripsToUserCity(
        EloquentBuilder | QueryBuilder $query,
        string $ownerKey,
        string $vehicleColumn,
        string $stopsTable,
        string $stopsOwnerKey,
    ): void {
        $department = self::userCityScope();
        if ($department === null) {
            return;
        }

        $base = self::userCityBaseQuery($query);
        if ($department === '') {
            $base->whereRaw('1 = 0');

            return;
        }

        $base->where(static function (QueryBuilder $where) use ($department, $ownerKey, $vehicleColumn, $stopsTable, $stopsOwnerKey): void {
            $where->whereExists(static function (QueryBuilder $vehicle) use ($department, $vehicleColumn): void {
                $vehicle->selectRaw('1')
                    ->from('fleet_vehicles as uc_v')
                    ->join('network_nodes as uc_n', 'uc_n.id', '=', 'uc_v.home_base_node_id')
                    ->whereColumn('uc_v.id', $vehicleColumn)
                    ->where('uc_n.department', $department);
            })->orWhereExists(static function (QueryBuilder $stop) use ($department, $ownerKey, $stopsTable, $stopsOwnerKey): void {
                $stop->selectRaw('1')
                    ->from($stopsTable.' as uc_t')
                    ->join('shipments as uc_s', 'uc_s.id', '=', 'uc_t.shipment_id')
                    ->whereColumn('uc_t.'.$stopsOwnerKey, $ownerKey);
                self::whereShipmentOfCity($stop, $department, 'uc_s.');
            });
        });
    }

    /**
     * Subconsulta «existe un envío de esta ciudad cuya columna `$shipmentKey` coincide con `$outerColumn`».
     *
     * @return Closure(QueryBuilder): void
     */
    private static function shipmentOfCity(string $department, string $shipmentKey, string $outerColumn): Closure
    {
        return static function (QueryBuilder $shipment) use ($department, $shipmentKey, $outerColumn): void {
            $shipment->selectRaw('1')
                ->from('shipments as uc_s')
                ->whereColumn('uc_s.'.$shipmentKey, $outerColumn);
            self::whereShipmentOfCity($shipment, $department, 'uc_s.');
        };
    }

    /**
     * El predicado único de la regla: origen o destino en el departamento, o ninguno de los dos resuelto.
     */
    private static function whereShipmentOfCity(QueryBuilder $query, string $department, string $prefix): void
    {
        $query->where(static function (QueryBuilder $city) use ($department, $prefix): void {
            $city->where($prefix.'origin_department', $department)
                ->orWhere($prefix.'destination_department', $department)
                ->orWhere(static function (QueryBuilder $unresolved) use ($prefix): void {
                    $unresolved->whereNull($prefix.'origin_department')->whereNull($prefix.'destination_department');
                });
        });
    }

    /**
     * Consulta base sobre la que se agregan los predicados (la de Eloquent expone la suya; es la misma
     * consulta, no una copia).
     *
     * @template TModel of Model
     *
     * @param EloquentBuilder<TModel> | QueryBuilder $query
     */
    private static function userCityBaseQuery(EloquentBuilder | QueryBuilder $query): QueryBuilder
    {
        return $query instanceof EloquentBuilder ? $query->getQuery() : $query;
    }

    /**
     * Código de departamento de la ciudad guardada en el usuario: minúsculas, sin tildes y con guiones bajos
     * («La Paz» → `la_paz`). Null si no hay nada utilizable.
     */
    private static function userCitySlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $plain = strtr(mb_strtolower(trim($value)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', $plain), '_');

        return $slug === '' ? null : $slug;
    }
}
