<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

/**
 * Resuelve el NOMBRE de un nodo logístico a partir de su id (UUID de `network_nodes`) para la capa
 * Filament de telemetría.
 *
 * Es una lectura cross-context de SOLO presentación: las geocercas guardan `node_ref` como UUID opaco del
 * contexto network, y aquí se muestra el nombre legible del nodo (CD, tienda...). El mapa id→nombre se
 * cachea en Valkey con TTL corto para evitar el N+1 (regla: ningún UUID visible en el admin). Antes se
 * memoizaba en una propiedad estática ("por request"), pero bajo Octane el worker persiste entre requests
 * y serviría nombres viejos indefinidamente; el caché con TTL se refresca solo.
 */
final class NodeNames
{
    /** Vida del caché del mapa (segundos); los nodos casi no cambian, 60s de staleness es aceptable. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Mapa id→nombre de todos los nodos de la red (memoizado por request).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return Cache::remember(
            'admin:node-names:telemetry',
            self::CACHE_TTL_SECONDS,
            static function (): array {
                /** @var array<string, string> $map */
                $map = NodeRecord::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();

                return $map;
            },
        );
    }

    /**
     * Nombre de un nodo por su id, o null si no existe.
     */
    public static function label(string $nodeId): ?string
    {
        return self::options()[$nodeId] ?? null;
    }
}
