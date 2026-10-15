<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;

/**
 * Puerto hacia el contexto de cobertura: dado el id de una zona, devuelve su perfil de servicio (días
 * que atiende, tiempo base y ventanas horarias) ya traducido a primitivos de promise.
 *
 * El dominio define QUÉ necesita para prometer (el perfil); CÓMO se lee de `logistics/coverage` lo
 * resuelve Infrastructure. Así este contexto no se acopla a coverage: lo conoce solo por este contrato y
 * por el id de zona como string.
 */
interface CoverageProfileProvider
{
    /**
     * Perfil de servicio de la zona, o null si la zona no existe o no está activa (no hay cobertura y por
     * lo tanto nada que prometer).
     */
    public function profileFor(string $zoneId): ?ZoneServiceProfile;
}
