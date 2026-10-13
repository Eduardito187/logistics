<?php

declare(strict_types=1);

namespace Logistics\Settings\Domain\ValueObject;

use Logistics\Settings\Domain\Exception\SettingsException;

/**
 * Clave namespaced de una regla configurable, por ejemplo `coverage.default_service_days`.
 *
 * El namespace (lo que va antes del punto) agrupa las reglas por área funcional —coberturas, cupos,
 * cortes, tarifas— y evita choques entre contextos. Se exige al menos un punto: una clave suelta
 * sin namespace no se acepta, para que toda regla quede clasificada desde el principio.
 */
final readonly class SettingKey
{
    /**
     * Minúsculas y guion bajo en cada segmento; segmentos separados por punto; mínimo dos segmentos.
     */
    private const PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw SettingsException::because("clave de configuración inválida: {$value}");
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
