<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordEvidenceValidation;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra el RESULTADO de validación anti-fraude de una evidencia (lo escribe el validador asíncrono /
 * sidecar on-premise tras procesar la firma o el documento).
 *
 * Dos modos:
 *  - `status` explícito (ok|review|mismatch): el validador ya decidió (p. ej. señal de firma).
 *  - `ocrDocumentNumber`: para id_document, el backend cruza on-premise ese número leído contra el
 *    documento del cliente (DocumentNumberMatcher) y deriva el estado. `status` gana si viene.
 *
 * La validación es SEÑAL: nunca bloquea la entrega; review/mismatch escalan a revisión humana.
 */
final readonly class RecordEvidenceValidation implements Command
{
    public function __construct(
        public string $evidenceId,
        public ?string $status = null,
        public ?string $ocrDocumentNumber = null,
        public ?string $detail = null,
        public ?string $validatedAt = null,
    ) {}
}
