<?php

declare(strict_types=1);

namespace Logistics\Settings\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto Configuración: clave mal formada, versión inconsistente, etc.
 */
final class SettingsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Configuración inválida: {$reason}");
    }
}
