<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Support;

use Logistics\Coverage\Application\Query\ListMunicipalities;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Helper de presentación: expone el catálogo de municipios de coverage como mapa id => etiqueta legible
 * ("Municipio — Departamento") para poblar los selects buscables del admin de capacity (filtro del
 * calendario, activación de cupos, bloqueo de días).
 *
 * Nota de arquitectura: lee SIEMPRE por el lado de consulta (QueryBus → ListMunicipalities), nunca toca
 * el dominio ni la persistencia de coverage. Memoiza el resultado por request: el municipio se usa en
 * varios selects de la misma pantalla y el catálogo es acotado (cientos de filas), así se evita repetir
 * la consulta. No es estado de dominio; es solo caché de presentación de su forma primitiva.
 */
final class MunicipalityOptions
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Mapa id => etiqueta de todos los municipios, ordenados por nombre (el handler ya ordena).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var array<int, MunicipalityView> $municipalities */
        $municipalities = $queryBus->ask(new ListMunicipalities);

        $options = [];
        foreach ($municipalities as $municipality) {
            $options[$municipality->id] = $municipality->label();
        }

        return self::$cache = $options;
    }

    /**
     * Etiqueta legible de un municipio por su id, o null si no está en el catálogo.
     */
    public static function labelFor(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return self::all()[$id] ?? null;
    }

    /**
     * Ids de todos los municipios (para expandir "todos los municipios" en la activación por rango).
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }
}
