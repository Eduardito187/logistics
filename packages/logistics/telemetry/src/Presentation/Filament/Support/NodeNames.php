<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Support;

use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

/**
 * Resuelve el NOMBRE de un nodo logístico a partir de su id (UUID de `network_nodes`) para la capa
 * Filament de telemetría.
 *
 * Es una lectura cross-context de SOLO presentación: las geocercas guardan `node_ref` como UUID opaco del
 * contexto network, y aquí se muestra el nombre legible del nodo (CD, tienda...). El mapa id→nombre se
 * carga una vez por request y se memoiza para evitar el N+1 (regla: ningún UUID visible en el admin).
 */
final class NodeNames
{
    /** @var array<string, string>|null Mapa memoizado id→nombre de todos los nodos. */
    private static ?array $map = null;

    /**
     * Mapa id→nombre de todos los nodos de la red (memoizado por request).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        if (self::$map === null) {
            /** @var array<string, string> $map */
            $map = NodeRecord::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
            self::$map = $map;
        }

        return self::$map;
    }

    /**
     * Nombre de un nodo por su id, o null si no existe.
     */
    public static function label(string $nodeId): ?string
    {
        return self::options()[$nodeId] ?? null;
    }
}
