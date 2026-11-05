<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Facades\DB;
use Logistics\Network\Application\Query\FindNode\FindNodeQuery;
use Logistics\Network\Application\Query\ListLegs\ListLegsQuery;
use Logistics\Network\Application\Query\ListReceivingDocks\ListReceivingDocksQuery;
use Logistics\Network\Application\ReadModel\LegView;
use Logistics\Network\Application\ReadModel\NodeView;
use Logistics\Network\Application\ReadModel\ReceivingDockView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un nodo de la red (almacén, tienda, punto de retiro): agrega su
 * ficha (código, tipo, departamento, ubicación), la flota con base en él, los envíos destinados, los
 * muelles de recepción (si es almacén) y sus conexiones (legs) con otros nodos. Prefiere los QueryHandlers
 * del QueryBus (CQRS) y cae a lectura directa para los conteos relacionales. Tolerante (cada fuente en su
 * try/catch). SOLO lectura; sin UUID visible (referencias: código/nombre del nodo, placa del vehículo).
 */
final class NodeHub
{
    /** Estados de envío terminales (no cuentan como "activos" destinados al nodo). */
    private const SHIPMENT_TERMINAL = ['DELIVERED', 'FAILED', 'CANCELLED'];

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Todo lo que la vista del hub del nodo necesita, en primitivos y tolerante.
     *
     * @return array{
     *   node: array{code: string, name: string, type: string, status: string, department: string|null, lat: float, lng: float}|null,
     *   fleet: array{total: int, vehicles: list<array{plate: string, status: string, label: string}>},
     *   shipments: array{total: int, active: int},
     *   docks: list<array{code: string, name: string, slots: int, active: bool}>,
     *   connections: list<array{other: string, corridor_type: string, direction: string}>,
     * }
     */
    public function overview(string $nodeId): array
    {
        return [
            'node' => $this->node($nodeId),
            'fleet' => $this->fleet($nodeId),
            'shipments' => $this->shipments($nodeId),
            'docks' => $this->docks($nodeId),
            'connections' => $this->connections($nodeId),
        ];
    }

    /**
     * Ficha del nodo, o null si no existe.
     *
     * @return array{code: string, name: string, type: string, status: string, department: string|null, lat: float, lng: float}|null
     */
    private function node(string $nodeId): ?array
    {
        try {
            $view = $this->queryBus->ask(new FindNodeQuery($nodeId));
            if (!$view instanceof NodeView) {
                return null;
            }

            return [
                'code' => $view->code,
                'name' => $view->name,
                'type' => $view->type,
                'status' => $view->status,
                'department' => $view->department,
                'lat' => $view->latitude,
                'lng' => $view->longitude,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Flota con base en el nodo (fleet_vehicles.home_base_node_id): conteo + lista corta.
     *
     * @return array{total: int, vehicles: list<array{plate: string, status: string, label: string}>}
     */
    private function fleet(string $nodeId): array
    {
        try {
            $total = DB::table('fleet_vehicles')->where('home_base_node_id', $nodeId)->count();

            $vehicles = DB::table('fleet_vehicles')
                ->where('home_base_node_id', $nodeId)
                ->orderBy('plate')
                ->limit(12)
                ->get(['plate', 'status', 'make', 'model'])
                ->map(static fn ($r): array => [
                    'plate' => (string) $r->plate,
                    'status' => (string) $r->status,
                    'label' => trim((string) $r->make.' '.(string) $r->model),
                ])
                ->all();

            return ['total' => $total, 'vehicles' => array_values($vehicles)];
        } catch (Throwable) {
            return ['total' => 0, 'vehicles' => []];
        }
    }

    /**
     * Envíos destinados al nodo (shipments.destination_node_id): total y activos (no terminales).
     *
     * @return array{total: int, active: int}
     */
    private function shipments(string $nodeId): array
    {
        try {
            $total = DB::table('shipments')->where('destination_node_id', $nodeId)->count();
            $active = DB::table('shipments')
                ->where('destination_node_id', $nodeId)
                ->whereNotIn('status', self::SHIPMENT_TERMINAL)
                ->count();

            return ['total' => $total, 'active' => $active];
        } catch (Throwable) {
            return ['total' => 0, 'active' => 0];
        }
    }

    /**
     * Muelles de recepción del nodo (si es almacén), o lista vacía.
     *
     * @return list<array{code: string, name: string, slots: int, active: bool}>
     */
    private function docks(string $nodeId): array
    {
        try {
            /** @var list<ReceivingDockView> $views */
            $views = $this->queryBus->ask(new ListReceivingDocksQuery($nodeId));
            $out = [];
            foreach ($views as $view) {
                $out[] = [
                    'code' => $view->code,
                    'name' => $view->name,
                    'slots' => $view->appointmentsPerSlot,
                    'active' => $view->active,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Conexiones (legs) del nodo con otros nodos, con el otro extremo resuelto a texto legible.
     *
     * @return list<array{other: string, corridor_type: string, direction: string}>
     */
    private function connections(string $nodeId): array
    {
        try {
            /** @var list<LegView> $legs */
            $legs = $this->queryBus->ask(new ListLegsQuery);

            // Resuelve de una vez los nombres de los otros nodos involucrados.
            $otherIds = [];
            foreach ($legs as $leg) {
                if ($leg->fromNodeId === $nodeId) {
                    $otherIds[] = $leg->toNodeId;
                } elseif ($leg->toNodeId === $nodeId) {
                    $otherIds[] = $leg->fromNodeId;
                }
            }
            $names = $this->nodeNames(array_values(array_unique($otherIds)));

            $out = [];
            foreach ($legs as $leg) {
                if ($leg->fromNodeId === $nodeId) {
                    $out[] = [
                        'other' => $names[$leg->toNodeId] ?? '—',
                        'corridor_type' => $leg->corridorType,
                        'direction' => 'out',
                    ];
                } elseif ($leg->toNodeId === $nodeId) {
                    $out[] = [
                        'other' => $names[$leg->fromNodeId] ?? '—',
                        'corridor_type' => $leg->corridorType,
                        'direction' => 'in',
                    ];
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Nombres legibles de nodos por id, en lote.
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function nodeNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            /** @var array<string, string> $names */
            $names = DB::table('network_nodes')
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->all();

            return $names;
        } catch (Throwable) {
            return [];
        }
    }
}
