<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Contract;

use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Puerto de la regla de cobertura por zona (CoverageRule) como configuración VERSIONADA.
 *
 * Las reglas de una zona —nodo que atiende, servicios, tiempo base, límites por producto, ventanas
 * horarias y días de servicio— son reglas de negocio que cambian con el tiempo; por eso (principio 5
 * de la arquitectura) no se guardan solo como estado editable, sino como configuración versionada con
 * historial y auditoría. Cada publicación crea una versión nueva; nunca se pisa la anterior, y se puede
 * leer la regla vigente a una fecha dada.
 *
 * La implementación vive en Infrastructure y confina ahí el cruce con el contexto Configuración
 * (settings), hablándole SOLO por los buses CQRS (sin dependencia composer), igual que fleet y
 * notifications. La zona mantiene además su copia vigente en jsonb como proyección de estado para el
 * admin tipado; la FUENTE DE VERDAD del historial es Configuración.
 */
interface ZoneRuleSettings
{
    /**
     * Publica la próxima versión de la regla de una zona. `createdBy` queda en la auditoría.
     */
    public function publish(string $zoneId, ZoneRules $rules, ?string $createdBy = null): void;

    /**
     * Regla vigente de una zona a la fecha dada (ISO 8601) o a "ahora" si viene null. Devuelve null si
     * la zona no tiene ninguna versión publicada o si la lectura no se pudo resolver.
     */
    public function effectiveFor(string $zoneId, ?string $at = null): ?ZoneRules;
}
