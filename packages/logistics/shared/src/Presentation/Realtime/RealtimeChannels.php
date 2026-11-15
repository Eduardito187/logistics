<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Realtime;

/**
 * Nombres de los canales de tiempo real por TEMA, compartidos por quien emite (cada contexto) y por quien
 * autoriza (routes/channels.php del host). PHP puro: solo arma cadenas.
 *
 * Cada tema operativo tiene dos clases de canal (nombre SIN el prefijo `private-`):
 *  - `<tema>.all`           : lo escucha quien ve todas las ciudades (personal sin ciudad asignada).
 *  - `<tema>.city.<ciudad>` : lo escucha el personal de esa ciudad (código de departamento, el mismo de
 *                             `users.city` y `network_nodes.department`: `santa_cruz`, `la_paz`, …).
 *
 * Un hecho de una ciudad se emite a AMBOS (`all` + el de su ciudad); uno sin ciudad conocida, solo a `all`.
 * La app nunca arma estos nombres: se los entrega `GET /api/v1/app/broadcasting/config`.
 */
final class RealtimeChannels
{
    public const string TOPIC_FLEET = 'fleet';

    public const string TOPIC_PLANNING = 'planning';

    /**
     * Canales a los que se EMITE un hecho del tema, según la ciudad a la que pertenece.
     *
     * @return list<string>
     */
    public static function forEvent(string $topic, ?string $city): array
    {
        $channels = [self::all($topic)];

        $cityChannel = self::city($topic, $city);
        if ($cityChannel !== null) {
            $channels[] = $cityChannel;
        }

        return $channels;
    }

    /** Canal de quien ve todas las ciudades. */
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
}
