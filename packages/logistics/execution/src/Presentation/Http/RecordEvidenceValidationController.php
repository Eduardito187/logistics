<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordEvidenceValidation\RecordEvidenceValidation;
use Logistics\Execution\Presentation\Http\Request\RecordEvidenceValidationRequest;

/**
 * POST /integrations/evidence/{evidenceId}/validation — el validador anti-fraude asíncrono (worker/sidecar
 * on-premise) registra el resultado de validar una evidencia (firma / documento).
 *
 * Es una señal, no un bloqueo: fija el estado (ok|review|mismatch) que la app consulta. Si no manda
 * `status` pero sí `ocr_document_number`, el backend cruza el número contra el documento del cliente.
 */
final class RecordEvidenceValidationController extends ExecutionController
{
    public function __invoke(RecordEvidenceValidationRequest $request, string $evidenceId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new RecordEvidenceValidation(
            evidenceId: $evidenceId,
            status: $this->nullableString($data, 'status'),
            ocrDocumentNumber: $this->nullableString($data, 'ocr_document_number'),
            detail: $this->nullableString($data, 'detail'),
            validatedAt: $this->nullableString($data, 'validated_at'),
        ));

        return new JsonResponse(['data' => ['evidence_id' => $evidenceId, 'status' => 'recorded']]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? $data[$key] : null;
    }
}
