<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

/**
 * Severidad de una alerta de telemetría. Ordena la atención de la operación: lo crítico primero.
 */
enum AlertSeverity: string
{
    /** Informativa: se registra, no exige acción inmediata. */
    case Info = 'info';

    /** Advertencia: conviene revisar (p. ej. exceso de velocidad puntual). */
    case Warning = 'warning';

    /** Crítica: requiere atención inmediata (p. ej. incursión en zona prohibida). */
    case Critical = 'critical';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }
}
