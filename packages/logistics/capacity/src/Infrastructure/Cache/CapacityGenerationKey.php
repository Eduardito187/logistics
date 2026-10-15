<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Cache;

/**
 * Clave de "generación" de cupos por día (frente E — escala).
 *
 * Es un contador por fecha que se incrementa cada vez que cambia CUALQUIER cupo de ese día (al
 * guardar un bucket: definir, consumir o liberar). Sirve como señal de invalidación basada en evento:
 * los caches que derivan de la capacidad de un día (p. ej. las franjas disponibles de promise) incluyen
 * este número en su clave, así que al cambiar la capacidad el número sube y las entradas viejas quedan
 * huérfanas sin tener que borrarlas una por una.
 *
 * Capacity es dueña de esta clave (es quien la incrementa); los contextos que leen capacidad —como
 * promise, que ya depende de este package— la consultan para construir sus propias claves. Así la
 * dependencia va en la dirección correcta (promise → capacity) y la convención vive en un solo lugar.
 */
final class CapacityGenerationKey
{
    private const string PREFIX = 'logistics:capacity:gen:';

    /**
     * Clave de generación para una fecha 'Y-m-d'. Se recorta a los 10 primeros caracteres por si
     * llega con hora, para que todas las franjas del mismo día compartan contador.
     */
    public static function forDate(string $date): string
    {
        return self::PREFIX.substr($date, 0, 10);
    }
}
