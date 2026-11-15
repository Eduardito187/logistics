<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Canales privados del tema PLANIFICACIÓN para un hecho que toca a un conjunto de ciudades: siempre
 * `planning.all` (quien ve todas las ciudades) y, por cada ciudad válida, `planning.city.<codigo>`.
 *
 * Los nombres los arma {@see RealtimeChannels} (contrato compartido con quien autoriza los canales); acá
 * solo se quitan repetidos y códigos inválidos.
 */
final class PlanningChannels
{
    /**
     * Nombres de canal SIN el prefijo `private-`.
     *
     * @param  list<string> $cities códigos de departamento (`santa_cruz`, `la_paz`, …)
     * @return list<string>
     */
    public static function names(array $cities): array
    {
        $names = [RealtimeChannels::all(RealtimeChannels::TOPIC_PLANNING) => true];

        foreach ($cities as $city) {
            $channel = RealtimeChannels::city(RealtimeChannels::TOPIC_PLANNING, $city);
            if ($channel !== null) {
                $names[$channel] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param  list<string>         $cities
     * @return list<PrivateChannel>
     */
    public static function for(array $cities): array
    {
        return array_map(
            static fn (string $name): PrivateChannel => new PrivateChannel($name),
            self::names($cities),
        );
    }
}
