<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shipments\Domain\Service\OriginNodeSelector;
use Logistics\Shipments\Domain\ValueObject\DepartmentCode;
use Throwable;

/**
 * Resuelve los campos de ALCANCE de un envío que se guardan en `shipments`: nodo y departamento de ORIGEN y
 * departamento de DESTINO. Con ellos el listado filtra por ciudad con columnas indexadas, sin derivar nada
 * en cada lectura.
 *
 * Destino (departamento):
 *  1. el del nodo destino (retiro en tienda, traspaso);
 *  2. si es a domicilio, el de su ciudad (El Alto → `la_paz`);
 *  3. si no, el del centro de distribución más cercano a las coordenadas de entrega.
 * Origen: el nodo informado por la fuente se RESPETA; si no vino, se elige con la regla de las etiquetas
 * ({@see OriginNodeSelector}). Su departamento es el del nodo de origen.
 *
 * Lee la red (`network_nodes`) en solo lectura y tolerante: si no se puede leer, los campos quedan sin
 * resolver (null) y el envío se muestra igual (no se esconde un pedido por un dato faltante).
 *
 * @phpstan-type NetworkNode array{id: string, code: string, type: string, department: string|null, lat: float|null, lng: float|null}
 * @phpstan-type ScopeFields array{origin_node_id: string|null, origin_department: string|null, destination_department: string|null}
 */
final readonly class ShipmentScopeFieldsResolver
{
    /** Tipos de nodo desde los que se prepara un pedido cuando la fuente no informó el origen. */
    private const array DEFAULT_ORIGIN_TYPES = ['distribution_center', 'central_warehouse'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private Repository $config,
        private OriginNodeSelector $selector,
    ) {}

    /**
     * Nodos de la red por id, en primitivos. Una sola consulta sobre una tabla chica; el comando de relleno
     * la lee una vez y la reutiliza con {@see resolveWith()}.
     *
     * @return array<string, NetworkNode>
     */
    public function networkNodes(): array
    {
        try {
            $rows = $this->connections->connection()->table('network_nodes')
                ->orderBy('code')
                ->get(['id', 'code', 'type', 'department', 'lat', 'lng']);
        } catch (Throwable) {
            return [];
        }

        $nodes = [];
        foreach ($rows as $row) {
            $id = is_scalar($row->id ?? null) ? strtolower((string) $row->id) : '';
            if ($id === '') {
                continue;
            }

            $nodes[$id] = [
                'id' => $id,
                'code' => is_scalar($row->code ?? null) ? (string) $row->code : '',
                'type' => is_scalar($row->type ?? null) ? (string) $row->type : '',
                'department' => DepartmentCode::of(is_scalar($row->department ?? null) ? (string) $row->department : null),
                'lat' => is_numeric($row->lat ?? null) ? (float) $row->lat : null,
                'lng' => is_numeric($row->lng ?? null) ? (float) $row->lng : null,
            ];
        }

        return $nodes;
    }

    /**
     * @return ScopeFields
     */
    public function resolve(
        ?string $destinationNodeId,
        ?string $city,
        ?float $lat,
        ?float $lng,
        ?string $orderReference,
        ?string $originNodeId,
    ): array {
        return $this->resolveWith($this->networkNodes(), $destinationNodeId, $city, $lat, $lng, $orderReference, $originNodeId);
    }

    /**
     * Misma regla, sobre una foto de la red ya leída (relleno por lotes: sin una consulta por envío).
     *
     * @param  array<string, NetworkNode> $nodes
     * @return ScopeFields
     */
    public function resolveWith(
        array $nodes,
        ?string $destinationNodeId,
        ?string $city,
        ?float $lat,
        ?float $lng,
        ?string $orderReference,
        ?string $originNodeId,
    ): array {
        $centers = $this->centers($nodes);

        $destinationNode = $destinationNodeId !== null ? ($nodes[strtolower($destinationNodeId)] ?? null) : null;
        $destinationDepartment = $destinationNode['department'] ?? null;
        $destinationDepartment ??= DepartmentCode::fromCity($city);
        if ($destinationDepartment === null && $lat !== null && $lng !== null) {
            $destinationDepartment = $this->selector->nearest($centers, $lat, $lng)['department'] ?? null;
        }

        // Origen informado por la fuente: se respeta tal cual (su departamento sale del propio nodo).
        if ($originNodeId !== null && $originNodeId !== '') {
            return [
                'origin_node_id' => strtolower($originNodeId),
                'origin_department' => $nodes[strtolower($originNodeId)]['department'] ?? null,
                'destination_department' => $destinationDepartment,
            ];
        }

        $origin = $this->selector->select(
            centers: $centers,
            destinationDepartment: $destinationDepartment,
            lat: $lat,
            lng: $lng,
            orderReference: $orderReference,
            prefixToDepartment: $this->prefixMap(),
            defaultCode: $this->defaultCode(),
        );

        return [
            'origin_node_id' => $origin['id'] ?? null,
            'origin_department' => $origin['department'] ?? null,
            'destination_department' => $destinationDepartment,
        ];
    }

    /**
     * Centros de distribución de la red (los nodos desde los que se prepara), en el orden estable de su código.
     *
     * @param  array<string, NetworkNode>                                                                       $nodes
     * @return list<array{id: string, code: string, department: string|null, lat: float|null, lng: float|null}>
     */
    private function centers(array $nodes): array
    {
        $types = $this->originTypes();

        $centers = [];
        foreach ($nodes as $node) {
            if (in_array($node['type'], $types, true)) {
                $centers[] = [
                    'id' => $node['id'],
                    'code' => $node['code'],
                    'department' => $node['department'],
                    'lat' => $node['lat'],
                    'lng' => $node['lng'],
                ];
            }
        }

        return $centers;
    }

    /**
     * @return list<string>
     */
    private function originTypes(): array
    {
        $configured = $this->config->get('logistics-shipments.scope.origin.node_types');

        $types = [];
        foreach (is_array($configured) ? $configured : [] as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }

        return $types === [] ? self::DEFAULT_ORIGIN_TYPES : $types;
    }

    /**
     * Prefijo de la referencia del pedido → código de departamento, desde la config del contexto.
     *
     * @return array<string, string>
     */
    private function prefixMap(): array
    {
        $configured = $this->config->get('logistics-shipments.scope.origin.reference_prefixes');

        $map = [];
        foreach (is_array($configured) ? $configured : [] as $prefix => $department) {
            $code = is_string($department) ? DepartmentCode::of($department) : null;
            if (is_string($prefix) && $prefix !== '' && $code !== null) {
                $map[$prefix] = $code;
            }
        }

        return $map;
    }

    private function defaultCode(): string
    {
        $configured = $this->config->get('logistics-shipments.scope.origin.default_node_code');

        return is_string($configured) ? $configured : '';
    }
}
