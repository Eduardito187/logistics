<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Enums\Role;
use App\Models\User;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Qué canales de tiempo real puede escuchar cada persona, por TEMA. Es la única fuente de esa decisión:
 * la usan `GET /api/v1/app/broadcasting/config` (para decirle a la app a qué suscribirse) y
 * routes/channels.php (para firmar o rechazar cada suscripción), así ambas respuestas nunca se contradicen.
 *
 * Temas:
 *  - `user`     : el canal personal (`user.{id}`), de cualquier usuario.
 *  - `fleet`    : flota en vivo.
 *  - `planning` : planes y corrida automática.
 *
 * `fleet` y `planning` son de oficina y planificación (admin, supervisor, planificador), con el mismo alcance
 * por ciudad que el resto de la plataforma: admin y supervisor ven todas las ciudades (canal `.all`); el
 * planificador, solo la suya (canal `.city.<código>`). Un planificador sin ciudad asignada ve todas, salvo
 * que el entorno pida el modo estricto (`logistics-shipments.scope.strict_without_city`): ahí no escucha nada.
 */
final class UserRealtimeChannels
{
    /** Temas con alcance por ciudad. */
    private const array CITY_TOPICS = [RealtimeChannels::TOPIC_FLEET, RealtimeChannels::TOPIC_PLANNING];

    /**
     * Canales por tema, con el prefijo `private-` que usa el cliente al suscribirse. Un tema sin canales no
     * aparece: la app sigue con su refresco normal para esa pantalla.
     *
     * @return array<string, list<string>>
     */
    public static function for(User $user): array
    {
        $channels = ['user' => ['private-user.'.(int) $user->getAuthIdentifier()]];

        foreach (self::CITY_TOPICS as $topic) {
            $channel = self::topicChannel($user, $topic);
            if ($channel !== null) {
                $channels[$topic] = ['private-'.$channel];
            }
        }

        return $channels;
    }

    /** ¿Puede esta persona escuchar el canal de todas las ciudades del tema? */
    public static function canJoinAll(User $user, string $topic): bool
    {
        return self::topicChannel($user, $topic) === RealtimeChannels::all($topic);
    }

    /** ¿Puede esta persona escuchar el canal de esa ciudad del tema? */
    public static function canJoinCity(User $user, string $topic, string $city): bool
    {
        $channel = RealtimeChannels::city($topic, $city);

        return $channel !== null && self::topicChannel($user, $topic) === $channel;
    }

    /**
     * El único canal del tema que le corresponde a la persona (sin prefijo), o null si no tiene ese tema.
     */
    private static function topicChannel(User $user, string $topic): ?string
    {
        if ($user->hasAnyRole(Role::Admin, Role::Supervisor)) {
            return RealtimeChannels::all($topic);
        }

        if (!$user->hasAnyRole(Role::Planificador)) {
            return null;
        }

        // Lectura tolerante: si la columna todavía no existe en este entorno, es «sin ciudad».
        $raw = $user->getAttributes()['city'] ?? null;
        $city = RealtimeChannels::city($topic, is_string($raw) ? $raw : null);
        if ($city !== null) {
            return $city;
        }

        return (bool) config('logistics-shipments.scope.strict_without_city', false)
            ? null
            : RealtimeChannels::all($topic);
    }
}
