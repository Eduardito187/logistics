<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Logistics\Coverage\Application\Query\ListMunicipalities;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Helper de presentación: expone el catálogo de municipios de coverage como mapa id => etiqueta legible
 * ("Municipio — Departamento") para poblar los selects buscables del admin de capacity (filtro del
 * calendario, activación de cupos, bloqueo de días).
 *
 * Nota de arquitectura: lee SIEMPRE por el lado de consulta (QueryBus → ListMunicipalities), nunca toca
 * el dominio ni la persistencia de coverage. El catálogo es acotado (cientos de filas) y casi no cambia,
 * así que se cachea su forma primitiva (array id => etiqueta) en Valkey con TTL corto. Antes se memoizaba
 * en una propiedad estática ("por request"), pero bajo Octane el worker vive entre requests y esa estática
 * serviría datos viejos indefinidamente y crecería; el caché con TTL se refresca solo y bajo FPM queda
 * incluso más rápido (compartido cross-request). No es estado de dominio; es caché de presentación.
 */
final class MunicipalityOptions
{
    /** Vida del caché del catálogo (segundos); dato de referencia, 60s de staleness es aceptable. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Mapa id => etiqueta de todos los municipios, ordenados por nombre (el handler ya ordena).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return Cache::remember(
            'admin:municipality-options',
            self::CACHE_TTL_SECONDS,
            static function (): array {
                /** @var QueryBus $queryBus */
                $queryBus = app(QueryBus::class);
                /** @var array<int, MunicipalityView> $municipalities */
                $municipalities = $queryBus->ask(new ListMunicipalities);

                $options = [];
                foreach ($municipalities as $municipality) {
                    $options[$municipality->id] = $municipality->label();
                }

                return $options;
            },
        );
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
