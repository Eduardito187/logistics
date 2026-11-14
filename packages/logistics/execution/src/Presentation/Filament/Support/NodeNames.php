<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

/**
 * Resuelve el NOMBRE legible de un nodo de la red a partir de su id (UUID de `network_nodes`) para la
 * capa Filament de Execution.
 *
 * Execution guarda los nodos como UUID sin FK dura (contexto desacoplable); aquí se enriquece con el
 * nombre legible leyendo del contexto Network en SOLO lectura (nunca para mutar). El mapa id→nombre se
 * cachea en Valkey con TTL corto para evitar el N+1 al pintar tablas e infolists. Antes se memoizaba en
 * una propiedad estática ("por request"), pero bajo Octane el worker vive entre requests y esa estática
 * serviría nombres viejos indefinidamente; el caché con TTL se refresca solo.
 */
final class NodeNames
{
    /** Vida del caché del mapa (segundos); los nodos casi no cambian, 60s de staleness es aceptable. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Nombre de un nodo por su id, o null si el id es vacío o el nodo ya no existe en Network.
     */
    public static function label(?string $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        return self::map()[$nodeId] ?? null;
    }

    /**
     * Mapa id→nombre de todos los nodos de la red (cacheado con TTL corto).
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        return Cache::remember(
            'admin:node-names:execution',
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
}
