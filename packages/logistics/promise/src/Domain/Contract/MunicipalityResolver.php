<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

/**
 * Puerto hacia el contexto de cobertura: resuelve a qué MUNICIPIO pertenece una coordenada.
 *
 * El municipio es el eje por el que capacity gestiona los cupos de despacho; el canal del cliente
 * entrega una dirección/coordenada (no conoce zonas ni municipios), y promise necesita traducirla al
 * municipio para ofrecer y reservar sobre el calendario correcto.
 *
 * El dominio define QUÉ necesita (punto → id de municipio); CÓMO se resuelve —con el MunicipalityLocator
 * de `logistics/coverage` y PostGIS— lo decide Infrastructure. Así promise no importa ese package: lo
 * conoce solo por este contrato y por primitivos (lat/lng como float, municipio como string).
 *
 * Es tolerante por contrato: si el punto no cae en ninguna zona servible o no se puede llegar a un
 * municipio, devuelve null (ubicación desconocida) en vez de lanzar.
 */
interface MunicipalityResolver
{
    public function resolve(float $latitude, float $longitude): ?string;
}
