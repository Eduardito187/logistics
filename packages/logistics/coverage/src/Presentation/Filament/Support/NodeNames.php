<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve la ETIQUETA legible de un nodo de la red ("name (code)") a partir de su id (UUID de
 * `network_nodes`) para la capa Filament de Coverage.
 *
 * Las reglas de zona guardan el nodo que atiende como UUID sin FK dura: cada contexto es extraíble a
 * microservicio, así que no se acopla Coverage al package Network vía Eloquent; se lee `network_nodes`
 * por consulta directa en SOLO lectura. El mapa id→etiqueta se carga una vez por request y se memoiza
 * para evitar el N+1 al pintar la columna "Serving node" de la tabla de zonas (auditoría M-3). Al
 * extraer el contexto, esta lectura pasará a una API de red.
 */
final class NodeNames
{
    /** @var array<string, string>|null Mapa memoizado id→"name (code)" de todos los nodos. */
    private static ?array $map = null;

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
     * Mapa id→"name (code)" de todos los nodos de la red (memoizado por request). Tolera la tabla
     * ausente devolviendo un mapa vacío para no romper el panel.
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$map === null) {
            try {
                self::$map = DB::table('network_nodes')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->mapWithKeys(static fn ($node): array => [
                        (string) $node->id => "{$node->name} ({$node->code})",
                    ])
                    ->all();
            } catch (\Throwable) {
                self::$map = [];
            }
        }

        return self::$map;
    }
}
