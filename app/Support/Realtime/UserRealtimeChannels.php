<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Enums\Role;
use App\Models\User;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Qué canal de tiempo real escucha cada persona por TEMA. Única fuente de esa decisión: la usan
 * `GET /api/v1/app/broadcasting/config` (para decirle a la app a qué suscribirse) y routes/channels.php
 * (para firmar o rechazar cada suscripción), así ambas respuestas nunca se contradicen.
 *
 * Cada persona recibe UN canal por tema, según su dimensión de alcance (la misma de su consulta de pedidos):
 *  - oficina (admin/supervisor) → `<tema>.all`
 *  - planificador / preparador  → `<tema>.city.<departamento>` (o `.all` si no tiene ciudad y no hay modo estricto)
 *  - tienda                     → `<tema>.node.<node_id>`
 *  - chofer                     → `<tema>.driver.<driver_id>`
 *  - transportista              → `<tema>.carrier.<carrier_id>`
 * El backend emite cada hecho a TODOS los canales que cubren a la entidad (ver `RealtimeChannels::forEntity`);
 * la app se suscribe al único que le toca y filtra por el id del payload. Un tema que un rol no opera no
 * aparece en su mapa: la app sigue con su refresco normal para esa pantalla.
 */
final class UserRealtimeChannels
{
    /** Dimensión de alcance de un canal. */
    private const string DIM_ALL = 'all';

    private const string DIM_CITY = 'city';

    private const string DIM_NODE = 'node';

    private const string DIM_DRIVER = 'driver';

    private const string DIM_CARRIER = 'carrier';

    /**
     * Qué roles reciben cada tema y con qué dimensión. Se evalúa en orden: la primera dimensión cuyo rol
     * tenga el usuario gana (oficina primero, luego ciudad, luego nodo/chofer/transportista).
     *
     * @var array<string, list<array{0: string, 1: list<Role>}>>
     */
    private const array TOPIC_MATRIX = [
        RealtimeChannels::TOPIC_FLEET => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_CITY, [Role::Planificador]],
        ],
        RealtimeChannels::TOPIC_PLANNING => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_CITY, [Role::Planificador]],
        ],
        RealtimeChannels::TOPIC_TRACKING => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_CITY, [Role::Planificador, Role::Preparador]],
            [self::DIM_NODE, [Role::Tienda]],
            [self::DIM_DRIVER, [Role::Chofer]],
            [self::DIM_CARRIER, [Role::Transportista]],
        ],
        RealtimeChannels::TOPIC_PICKING => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_CITY, [Role::Preparador, Role::Planificador]],
        ],
        RealtimeChannels::TOPIC_STORE => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_NODE, [Role::Tienda]],
        ],
        RealtimeChannels::TOPIC_OPERATIONS => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_CITY, [Role::Planificador]],
        ],
        RealtimeChannels::TOPIC_LOADING => [
            [self::DIM_ALL, [Role::Admin, Role::Supervisor]],
            [self::DIM_CITY, [Role::Preparador]],
            [self::DIM_DRIVER, [Role::Chofer]],
        ],
    ];

    /**
     * Mapa tema → canales (con prefijo `private-`) que esta persona puede escuchar.
     *
     * @return array<string, list<string>>
     */
    public static function for(User $user): array
    {
        $channels = ['user' => ['private-user.'.(int) $user->getAuthIdentifier()]];

        foreach (array_keys(self::TOPIC_MATRIX) as $topic) {
            $channel = self::topicChannel($user, $topic);
            if ($channel !== null) {
                $channels[$topic] = ['private-'.$channel];
            }
        }

        return $channels;
    }

    /**
     * ¿Puede esta persona suscribirse a este canal (sin prefijo `private-`)? True solo si es EXACTAMENTE el
     * canal que le corresponde a su rol/alcance para ese tema. Cubre todas las dimensiones.
     */
    public static function mayJoin(User $user, string $channel): bool
    {
        $topic = strstr($channel, '.', true);

        return is_string($topic) && $topic !== '' && self::topicChannel($user, $topic) === $channel;
    }

    /** Compatibilidad: ¿su canal del tema es el `.all`? */
    public static function canJoinAll(User $user, string $topic): bool
    {
        return self::topicChannel($user, $topic) === RealtimeChannels::all($topic);
    }

    /** Compatibilidad: ¿su canal del tema es el de esa ciudad? */
    public static function canJoinCity(User $user, string $topic, string $city): bool
    {
        $channel = RealtimeChannels::city($topic, $city);

        return $channel !== null && self::topicChannel($user, $topic) === $channel;
    }

    /**
     * El único canal del tema que le corresponde a la persona (sin prefijo), o null si no opera ese tema.
     */
    private static function topicChannel(User $user, string $topic): ?string
    {
        foreach (self::TOPIC_MATRIX[$topic] ?? [] as [$dimension, $roles]) {
            if (!$user->hasAnyRole(...$roles)) {
                continue;
            }

            return self::channelForDimension($user, $topic, $dimension);
        }

        return null;
    }

    /** Arma el canal de la dimensión que le toca al usuario en ese tema (null si le falta el dato). */
    private static function channelForDimension(User $user, string $topic, string $dimension): ?string
    {
        return match ($dimension) {
            self::DIM_ALL => RealtimeChannels::all($topic),
            self::DIM_CITY => self::cityChannel($user, $topic),
            self::DIM_NODE => RealtimeChannels::node($topic, self::attr($user, 'node_id')),
            self::DIM_DRIVER => RealtimeChannels::driver($topic, self::attr($user, 'driver_id')),
            self::DIM_CARRIER => RealtimeChannels::carrier($topic, self::attr($user, 'carrier_id')),
            default => null,
        };
    }

    /**
     * Canal por ciudad del tema; sin ciudad asignada, el de todas, salvo que el entorno pida el modo estricto
     * (ahí no escucha nada).
     */
    private static function cityChannel(User $user, string $topic): ?string
    {
        $strict = (bool) config('logistics-shipments.scope.strict_without_city', false);

        return RealtimeChannels::city($topic, self::attr($user, 'city'))
            ?? ($strict ? null : RealtimeChannels::all($topic));
    }

    /** Lectura tolerante de un atributo del usuario (si la columna no existe aún, null). */
    private static function attr(User $user, string $name): ?string
    {
        $value = $user->getAttributes()[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
