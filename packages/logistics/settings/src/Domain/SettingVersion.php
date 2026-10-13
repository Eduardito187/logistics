<?php

declare(strict_types=1);

namespace Logistics\Settings\Domain;

use DateTimeImmutable;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\ValueObject\SettingId;
use Logistics\Settings\Domain\ValueObject\SettingKey;

/**
 * Una versión concreta del valor de una clave de configuración.
 *
 * Es inmutable a propósito: cada cambio de una regla crea una versión NUEVA; la anterior nunca se
 * pisa (docs/architecture.md principio 5). El historial completo de una clave es la lista de sus
 * versiones; "el valor vigente a una fecha" es la mayor versión cuyo `effectiveFrom` es <= esa fecha.
 *
 * El `value` se guarda como arreglo primitivo (lo que termina serializado a JSON), no como objeto de
 * dominio tipado, porque este contexto no interpreta la regla: solo la versiona y la sirve. Quien la
 * consume (coberturas, cupos, tarifas) la interpreta en su propio contexto.
 */
final readonly class SettingVersion
{
    /**
     * @param array<string, mixed> $value
     */
    private function __construct(
        public SettingId $id,
        public SettingKey $key,
        public int $version,
        public array $value,
        public DateTimeImmutable $effectiveFrom,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy,
    ) {}

    /**
     * Crea la próxima versión de una clave. `$version` lo provee el repositorio (max por clave + 1).
     *
     * @param array<string, mixed> $value
     */
    public static function create(
        SettingId $id,
        SettingKey $key,
        int $version,
        array $value,
        DateTimeImmutable $effectiveFrom,
        DateTimeImmutable $createdAt,
        ?string $createdBy = null,
    ): self {
        if ($version < 1) {
            throw SettingsException::because("la versión debe ser >= 1, se recibió {$version}");
        }

        return new self($id, $key, $version, $value, $effectiveFrom, $createdAt, $createdBy);
    }

    /**
     * Rehidrata una versión ya persistida sin revalidar el número (viene de la base, ya es consistente).
     *
     * @param array<string, mixed> $value
     */
    public static function reconstitute(
        SettingId $id,
        SettingKey $key,
        int $version,
        array $value,
        DateTimeImmutable $effectiveFrom,
        DateTimeImmutable $createdAt,
        ?string $createdBy,
    ): self {
        return new self($id, $key, $version, $value, $effectiveFrom, $createdAt, $createdBy);
    }

    /**
     * ¿Esta versión está vigente a la fecha dada? (su `effectiveFrom` ya ocurrió o es exactamente esa).
     */
    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        return $this->effectiveFrom <= $at;
    }
}
