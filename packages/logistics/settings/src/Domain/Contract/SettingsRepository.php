<?php

declare(strict_types=1);

namespace Logistics\Settings\Domain\Contract;

use DateTimeImmutable;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingKey;

/**
 * Puerto de persistencia de las versiones de configuración.
 *
 * El dominio y la capa de aplicación dependen de esta interfaz; la implementación Eloquent vive en
 * Infrastructure. Nunca se borra ni se modifica una versión: solo se agregan y se leen.
 */
interface SettingsRepository
{
    /**
     * Próximo número de versión para una clave: max(version) + 1, o 1 si la clave no existe aún.
     */
    public function nextVersionFor(SettingKey $key): int;

    /**
     * Persiste una versión nueva. El índice único (key, version) protege de carreras.
     */
    public function add(SettingVersion $v): void;

    /**
     * Valor vigente a la fecha `$at`: la mayor versión de la clave con `effectiveFrom` <= `$at`.
     * Devuelve null si no hay ninguna vigente a esa fecha.
     */
    public function currentFor(SettingKey $key, DateTimeImmutable $at): ?SettingVersion;

    /**
     * Historial completo de una clave, de la versión más nueva a la más vieja.
     *
     * @return list<SettingVersion>
     */
    public function historyFor(SettingKey $key): array;
}
