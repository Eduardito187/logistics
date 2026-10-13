<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Tipo de evidencia fotográfica que se captura en terreno y se sube al almacenamiento.
 *
 * Distingue la prueba de entrega (foto/firma del cliente al recibir) de la evidencia de daños
 * (reclamos/siniestros). Cada tipo vive en su propio disco S3-compatible con su ciclo de vida
 * (ver config/filesystems.php); el valor del caso es la clave del tipo, no el nombre del disco.
 */
enum EvidenceKind: string
{
    /** Prueba de entrega: foto o firma del cliente en el punto de entrega. */
    case ProofOfDelivery = 'pod';

    /** Evidencia de daños: foto del bulto dañado para un reclamo o siniestro. */
    case Damage = 'damage';

    /**
     * Resuelve el tipo desde su código; cualquier valor desconocido o vacío cae a prueba de entrega,
     * el caso por defecto de la subida de fotos.
     */
    public static function fromCode(?string $code): self
    {
        return self::tryFrom(trim((string) $code)) ?? self::ProofOfDelivery;
    }
}
