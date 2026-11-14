<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve la ETIQUETA legible de un nodo de la red ("name (code)") a partir de su id (UUID de
 * `network_nodes`) para la capa Filament de Coverage.
 *
 * Las reglas de zona guardan el nodo que atiende como UUID sin FK dura: cada contexto es extraíble a
 * microservicio, así que no se acopla Coverage al package Network vía Eloquent; se lee `network_nodes`
 * por consulta directa en SOLO lectura. El mapa id→etiqueta se cachea en Valkey con TTL corto para evitar
 * el N+1 al pintar la columna "Serving node" de la tabla de zonas (auditoría M-3). Antes se memoizaba en
 * una propiedad estática ("por request"), pero bajo Octane el worker vive entre requests y esa estática
 * serviría nombres viejos indefinidamente; el caché con TTL se refresca solo. Al extraer el contexto,
 * esta lectura pasará a una API de red.
 */
final class NodeNames
{
    /** Vida del caché del mapa (segundos); los nodos casi no cambian, 60s de staleness es aceptable. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Etiqueta de un nodo por su id, o null si el id es vacío o el nodo ya no existe en Network.
     */
    public static function label(?string $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        return self::map()[$nodeId] ?? null;
    }

    /**
     * Mapa id→"name (code)" de todos los nodos de la red (cacheado con TTL corto). Tolera la tabla
     * ausente devolviendo un mapa vacío para no romper el panel.
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        return Cache::remember(
            'admin:node-names:coverage',
            self::CACHE_TTL_SECONDS,
            static function (): array {
                try {
                    return DB::table('network_nodes')
                        ->orderBy('name')
                        ->get(['id', 'name', 'code'])
                        ->mapWithKeys(static fn ($node): array => [
                            (string) $node->id => "{$node->name} ({$node->code})",
                        ])
                        ->all();
                } catch (\Throwable) {
                    return [];
                }
            },
        );
    }
}
