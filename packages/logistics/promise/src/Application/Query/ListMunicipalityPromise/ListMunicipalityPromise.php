<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListMunicipalityPromise;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta la PROMESA de entrega para el cliente: dadas las coordenadas de una dirección (o un envío ya
 * geocodificado, o un municipio ya conocido), devuelve las fechas y franjas DISPONIBLES para despacho en
 * su municipio, respetando cobertura (resolución del municipio), capacidad (cupo libre), feriados (días
 * bloqueados) y el horizonte activo de cupos del municipio.
 *
 * Es la consulta que la puerta `channels` usa para mostrarle al cliente sus opciones reales de entrega
 * antes de reservar. DTO inmutable de primitivos; no viaja PII en la respuesta.
 *
 * El destino se identifica por una de tres vías (precedencia: municipio > coordenada > envío):
 *  - $municipalityId: municipio ya conocido por el canal.
 *  - $latitude/$longitude: coordenada de la dirección de entrega.
 *  - $shipmentId: envío del que se lee su coordenada.
 */
final readonly class ListMunicipalityPromise implements Query
{
    public function __construct(
        public ?string $municipalityId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $shipmentId = null,
        public ?string $fromDate = null,
        public int $horizonDays = 14,
    ) {}
}
