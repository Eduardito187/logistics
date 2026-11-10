<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Estado de validación anti-fraude de una evidencia.
 *
 * La validación (firma / número de documento) es una SEÑAL de apoyo, NUNCA un bloqueo automático de la
 * entrega (ver ADR de evidencia anti-fraude): `review` y `mismatch` escalan a revisión humana.
 *
 *  - Pending:  aún no validada (recién capturada; el worker/sidecar la procesará asíncrono).
 *  - Ok:       validación conforme (p. ej. número de documento coincide con el del cliente).
 *  - Review:   dudosa; requiere revisión humana (baja confianza, diferencia explicable por OCR, etc.).
 *  - Mismatch: discrepancia clara (p. ej. el documento no coincide con el del cliente).
 */
enum EvidenceValidationStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Review = 'review';
    case Mismatch = 'mismatch';

    public static function fromCode(?string $code): self
    {
        return self::tryFrom(trim((string) $code)) ?? self::Pending;
    }
}
