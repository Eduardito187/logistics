<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\ReadModel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Logistics\Labels\Application\Contract\OriginResolver;
use Logistics\Labels\Domain\Service\OriginCdSelector;
use Throwable;

/**
 * Resuelve el CÓDIGO del CD de origen de un envío (campo `org` del QR) leyendo la red en SOLO lectura y
 * tolerante, y delegando la regla pura en OriginCdSelector. Cross-context por lectura directa de tablas
 * (no acopla al dominio de red); si una tabla/columna falta, degrada al default configurado.
 */
final readonly class EloquentOriginResolver implements OriginResolver
{
    public function __construct(
        private OriginCdSelector $selector,
        private Repository $config,
    ) {}

    public function resolve(?string $destinationNodeId, ?float $lat, ?float $lng, ?string $orderReference): string
    {
        return $this->selector->select(
            cds: $this->distributionCenters(),
            destinationDepartment: $this->departmentOfNode($destinationNodeId),
            lat: $lat,
            lng: $lng,
            orderReference: $orderReference,
            prefixToDepartment: $this->prefixMap(),
            defaultCode: $this->defaultCode(),
        );
    }

    /**
     * Centros de distribución de la red (code + departamento + coordenadas).
     *
     * @return list<array{code: string, department: string, lat: float|null, lng: float|null}>
     */
    private function distributionCenters(): array
    {
        try {
            $out = [];
            foreach (DB::table('network_nodes')
                ->whereIn('type', ['distribution_center', 'central_warehouse'])
                ->get(['code', 'department', 'lat', 'lng']) as $row) {
                if (!is_string($row->code) || $row->code === '') {
                    continue;
                }
                $out[] = [
                    'code' => $row->code,
                    'department' => is_string($row->department) ? $row->department : '',
                    'lat' => is_numeric($row->lat) ? (float) $row->lat : null,
                    'lng' => is_numeric($row->lng) ? (float) $row->lng : null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Departamento del nodo destino, o null si no hay nodo (domicilio) o no se pudo leer. */
    private function departmentOfNode(?string $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        try {
            $department = DB::table('network_nodes')->where('id', $nodeId)->value('department');

            return is_string($department) && $department !== '' ? $department : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Prefijo de la referencia del pedido → departamento (fallback sin coordenadas), desde la config.
     *
     * @return array<string, string>
     */
    private function prefixMap(): array
    {
        $configured = $this->config->get('logistics-labels.origin.reference_prefixes');
        if (!is_array($configured)) {
            return [];
        }

        $out = [];
        foreach ($configured as $prefix => $department) {
            if (is_string($prefix) && is_string($department) && $department !== '') {
                $out[$prefix] = $department;
            }
        }

        return $out;
    }

    /** Código del CD por defecto cuando nada resuelve. */
    private function defaultCode(): string
    {
        $configured = $this->config->get('logistics-labels.origin.default_node_code');

        return is_string($configured) ? $configured : '';
    }
}
