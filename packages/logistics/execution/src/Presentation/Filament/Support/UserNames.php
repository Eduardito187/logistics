<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Resuelve el NOMBRE legible de un usuario a partir de su id (`users.id`) para la capa Filament de
 * Execution.
 *
 * Los escaneos guardan `user_id` como identificador del operador; aquí se enriquece con el nombre
 * legible leyendo `users` en SOLO lectura (nunca para mutar). El mapa id→nombre se memoiza por request
 * (evita el N+1 al pintar tablas e infolists) y además se cachea en Redis con TTL corto (auditoría
 * M-1): así no se relee la tabla `users` completa en cada request de panel. Se cachea la forma
 * primitiva (array id→nombre), nunca objetos de dominio (regla 7).
 */
final class UserNames
{
    /** Vida del caché del mapa de nombres (segundos); los nombres casi no cambian, 60s sobra. */
    private const MAP_TTL_SECONDS = 60;

    /** @var array<string, string>|null Mapa memoizado id→nombre de todos los usuarios. */
    private static ?array $map = null;

    /**
     * Nombre de un usuario por su id; si no se resuelve (p. ej. un código de operador que no es un
     * usuario de la plataforma), devuelve el valor original tal cual SIEMPRE QUE no sea un UUID. Un id
     * tipo UUID no resuelto se oculta como '—' para no exponer identificadores crudos.
     */
    public static function label(?string $userId): string
    {
        if ($userId === null || $userId === '') {
            return '—';
        }

        $name = self::map()[$userId] ?? null;
        if ($name !== null) {
            return $name;
        }

        // Valor no resuelto: si parece un UUID, se oculta; si es un código legible, se muestra.
        return self::looksLikeUuid($userId) ? '—' : $userId;
    }

    /**
     * Mapa id→nombre de todos los usuarios (memoizado por request). Las claves se normalizan a string
     * porque `users.id` puede ser entero y `user_id` llega como texto.
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$map === null) {
            self::$map = Cache::remember(
                'execution:user-names',
                self::MAP_TTL_SECONDS,
                static function (): array {
                    $map = [];
                    foreach (User::query()->get(['id', 'name']) as $user) {
                        $map[(string) $user->getKey()] = (string) $user->name;
                    }

                    return $map;
                },
            );
        }

        return self::$map;
    }

    /**
     * Heurística mínima para detectar un UUID (cualquier versión) y así nunca mostrarlo crudo.
     */
    private static function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
