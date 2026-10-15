<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Support;

use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

/**
 * Resuelve el NOMBRE legible de un nodo de la red a partir de su id (UUID de `network_nodes`) para la
 * capa Filament de Execution.
 *
 * Execution guarda los nodos como UUID sin FK dura (contexto desacoplable); aquí se enriquece con el
 * nombre legible leyendo del contexto Network en SOLO lectura (nunca para mutar). El mapa id→nombre se
 * carga una vez por request y se memoiza para evitar el N+1 al pintar tablas e infolists.
 */
final class NodeNames
{
    /** @var array<string, string>|null Mapa memoizado id→nombre de todos los nodos. */
    private static ?array $map = null;

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
     * Mapa id→nombre de todos los nodos de la red (memoizado por request).
     *
     * @return array<string, string>
     */
    private static function map(): array
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
}
