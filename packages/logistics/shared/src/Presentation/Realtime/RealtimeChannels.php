<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Realtime;

/**
 * Nombres de los canales de tiempo real por TEMA, compartidos por quien emite (cada contexto) y por quien
 * autoriza (routes/channels.php del host). PHP puro: solo arma cadenas.
 *
 * Cada tema operativo tiene canales por DIMENSIÓN de alcance (nombre SIN el prefijo `private-`):
 *  - `<tema>.all`              : quien ve todas las ciudades (oficina).
 *  - `<tema>.city.<ciudad>`    : personal de esa ciudad (código de departamento, el de `users.city` /
 *                                `network_nodes.department`: `santa_cruz`, `la_paz`, …).
 *  - `<tema>.node.<uuid>`      : personal de ese nodo/tienda.
 *  - `<tema>.driver.<uuid>`    : ese chofer.
 *  - `<tema>.carrier.<uuid>`   : ese transportista.
 *  - `<tema>.vehicle.<uuid>`   : quien espera ese camión (chofer/ayudante del vehículo).
 *
 * Un hecho se emite a TODOS los canales que cubren a la entidad (ver `forEntity`); cada persona se suscribe a
 * UNO por tema según su rol/alcance (lo decide `App\Support\Realtime\UserRealtimeChannels`). La app nunca arma
 * estos nombres: se los entrega `GET /api/v1/app/broadcasting/config`.
 */
final class RealtimeChannels
{
    public const string TOPIC_FLEET = 'fleet';

    public const string TOPIC_PLANNING = 'planning';

    public const string TOPIC_TRACKING = 'tracking';

    public const string TOPIC_PICKING = 'picking';

    public const string TOPIC_STORE = 'store';

    public const string TOPIC_OPERATIONS = 'operations';

    public const string TOPIC_LOADING = 'loading';

    /**
     * Canales a los que se EMITE un hecho del tema, según la ciudad a la que pertenece (compatibilidad:
     * `all` + ciudad). Para hechos con más dimensiones usar `forEntity`.
     *
     * @return list<string>
     */
    public static function forEvent(string $topic, ?string $city): array
    {
        return self::forEntity($topic, ['city' => $city]);
    }

    /**
     * Todos los canales que cubren a la entidad de un hecho, según las dimensiones que se conozcan. Siempre
     * incluye `<tema>.all`; suma ciudad/nodo/chofer/transportista/camión cuando vienen y son válidos. El
     * resultado se deduplica preservando el orden.
     *
     * @param  array{city?: ?string, node?: ?string, driver?: ?string, carrier?: ?string, vehicle?: ?string} $dims
     * @return list<string>
     */
    public static function forEntity(string $topic, array $dims): array
    {
        $channels = [self::all($topic)];

        foreach ([
            self::city($topic, $dims['city'] ?? null),
            self::node($topic, $dims['node'] ?? null),
            self::driver($topic, $dims['driver'] ?? null),
            self::carrier($topic, $dims['carrier'] ?? null),
            self::vehicle($topic, $dims['vehicle'] ?? null),
        ] as $channel) {
            if ($channel !== null && !in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /** Canal de quien ve todas las ciudades (oficina). */
    public static function all(string $topic): string
    {
        return $topic.'.all';
    }

    /** Canal de una ciudad; null si la ciudad viene vacía o no es un código válido. */
    public static function city(string $topic, ?string $city): ?string
    {
        $code = self::cityCode($city);

        return $code === null ? null : $topic.'.city.'.$code;
    }

    /** Canal de un nodo/tienda (por uuid); null si el id no es un uuid válido. */
    public static function node(string $topic, ?string $nodeId): ?string
    {
        return self::idChannel($topic, 'node', $nodeId);
    }

    /** Canal de un chofer (por uuid de driver); null si el id no es un uuid válido. */
    public static function driver(string $topic, ?string $driverId): ?string
    {
        return self::idChannel($topic, 'driver', $driverId);
    }

    /** Canal de un transportista (por uuid de carrier); null si el id no es un uuid válido. */
    public static function carrier(string $topic, ?string $carrierId): ?string
    {
        return self::idChannel($topic, 'carrier', $carrierId);
    }

    /** Canal de un vehículo (por uuid); null si el id no es un uuid válido. */
    public static function vehicle(string $topic, ?string $vehicleId): ?string
    {
        return self::idChannel($topic, 'vehicle', $vehicleId);
    }

    /**
     * Código de ciudad apto para un nombre de canal: minúsculas, letras, dígitos y guion bajo.
     */
    public static function cityCode(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $code = strtolower(trim($city));

        return $code !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : null;
    }

    /** ¿Es un UUID canónico? Validar antes de armar un canal por id (evita nombres raros). */
    public static function isUuid(?string $id): bool
    {
        return $id !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($id)) === 1;
    }

    /** Canal `<tema>.<dim>.<uuid>` en minúsculas; null si el id no es un uuid. */
    private static function idChannel(string $topic, string $dimension, ?string $id): ?string
    {
        return self::isUuid($id) ? $topic.'.'.$dimension.'.'.strtolower(trim((string) $id)) : null;
    }
}
