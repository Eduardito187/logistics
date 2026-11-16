<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Lecturas de ALCANCE cross-context para las señales de tiempo real del host (temas picking / store /
 * operations). Resuelve, SOLO por el query builder, el departamento (ciudad) y el nodo de un envío, de un
 * plan o de una referencia de pedido, sin acoplarse a los contextos emisores (envíos, planificación, …).
 *
 * Tolerante por diseño: cualquier error, tabla ausente o dato que falte devuelve null / lista vacía (la señal
 * cae al canal general o no se emite). Nunca lanza: corre dentro de un observador del outbox y el relay traga
 * los errores, pero igual se protege acá.
 *
 * Nunca compara contra una columna `uuid` un texto sin forma de UUID: en PostgreSQL eso falla con 22P02, así
 * que los ids se validan antes de tocar la base. Las referencias de pedido son texto (varchar): comparan sin
 * riesgo.
 */
final class RealtimeScopeReader
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Tope de envíos de un mismo pedido que se evalúan (un pedido tiene un puñado de destinos). */
    private const int ORDER_LIMIT = 200;

    /**
     * Alcance de un envío: su ciudad (departamento de origen o, en su defecto, de destino), su nodo destino y
     * la referencia de su pedido. Null si el id no tiene forma de UUID o el envío no existe.
     *
     * @return array{city: string | null, node: string | null, order_reference: string | null} | null
     */
    public function shipmentScope(?string $shipmentId): ?array
    {
        $id = self::uuid($shipmentId);
        if ($id === null) {
            return null;
        }

        $row = $this->first(
            DB::table('shipments')->where('id', $id),
            ['origin_department', 'destination_department', 'destination_node_id', 'order_reference'],
        );

        return $row === null ? null : [
            'city' => self::text($row->origin_department ?? null) ?? self::text($row->destination_department ?? null),
            'node' => self::uuid(self::text($row->destination_node_id ?? null)),
            'order_reference' => self::text($row->order_reference ?? null),
        ];
    }

    /**
     * Ciudad (departamento) de un vehículo: el departamento de su nodo base (`fleet_vehicles.home_base_node_id`
     * → `network_nodes.department`). Sirve para ubicar los hechos de ruta del tablero, que llevan el vehículo
     * pero no el envío. Null si el id no es UUID, el vehículo no existe o no tiene nodo base con departamento.
     */
    public function vehicleCity(?string $vehicleId): ?string
    {
        $id = self::uuid($vehicleId);
        if ($id === null) {
            return null;
        }

        $row = $this->first(
            DB::table('fleet_vehicles as v')
                ->leftJoin('network_nodes as n', 'n.id', '=', 'v.home_base_node_id')
                ->where('v.id', $id),
            ['n.department'],
        );

        return $row === null ? null : self::text($row->department ?? null);
    }

    /**
     * Ciudad (departamento) de un plan de reparto: la del primer envío de sus paradas con departamento. Null
     * si el id no es UUID o el plan no tiene paradas con departamento conocido.
     */
    public function planCity(?string $planId): ?string
    {
        $id = self::uuid($planId);
        if ($id === null) {
            return null;
        }

        try {
            $rows = DB::table('planning_plan_stops as ps')
                ->join('shipments as s', 's.id', '=', 'ps.shipment_id')
                ->where('ps.plan_id', $id)
                ->limit(self::ORDER_LIMIT)
                ->get(['s.origin_department', 's.destination_department']);
        } catch (Throwable) {
            return null;
        }

        return self::firstDepartment($rows->all());
    }

    /**
     * Ciudad (departamento) de una referencia de pedido: la del primer envío de ese pedido con departamento.
     */
    public function orderReferenceCity(?string $orderReference): ?string
    {
        $ref = self::text($orderReference);
        if ($ref === null) {
            return null;
        }

        try {
            $rows = DB::table('shipments')
                ->where('order_reference', $ref)
                ->limit(self::ORDER_LIMIT)
                ->get(['origin_department', 'destination_department']);
        } catch (Throwable) {
            return null;
        }

        return self::firstDepartment($rows->all());
    }

    /**
     * Nodos destino (tiendas) de los envíos de una referencia de pedido, sin repetir. Vacío si la referencia
     * viene vacía o ningún envío tiene nodo destino.
     *
     * @return list<string>
     */
    public function orderReferenceNodes(?string $orderReference): array
    {
        $ref = self::text($orderReference);
        if ($ref === null) {
            return [];
        }

        try {
            $values = DB::table('shipments')
                ->where('order_reference', $ref)
                ->whereNotNull('destination_node_id')
                ->limit(self::ORDER_LIMIT)
                ->pluck('destination_node_id')
                ->all();
        } catch (Throwable) {
            return [];
        }

        return self::uniqueNodes($values);
    }

    /**
     * Primera fila de la consulta, o null si no hay o la base falla (tolerante).
     *
     * @param list<string> $columns
     */
    private function first(Builder $query, array $columns): ?stdClass
    {
        try {
            /** @var stdClass|null $row */
            $row = $query->first($columns);
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /**
     * Primer departamento no vacío de un conjunto de filas (origen o, en su defecto, destino).
     *
     * @param array<int, stdClass> $rows
     */
    private static function firstDepartment(array $rows): ?string
    {
        foreach ($rows as $row) {
            $city = self::text($row->origin_department ?? null) ?? self::text($row->destination_department ?? null);
            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    /**
     * Nodos con forma de UUID, en minúsculas y sin repetidos.
     *
     * @param  array<array-key, mixed> $values
     * @return list<string>
     */
    private static function uniqueNodes(array $values): array
    {
        $nodes = [];
        foreach ($values as $value) {
            $node = self::uuid(self::text($value));
            if ($node !== null) {
                $nodes[$node] = true;
            }
        }

        return array_map(strval(...), array_keys($nodes));
    }

    /** UUID canónico en minúsculas, o null si el valor no lo es. */
    private static function uuid(?string $value): ?string
    {
        return $value !== null && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    /** Texto recortado no vacío, o null. */
    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
