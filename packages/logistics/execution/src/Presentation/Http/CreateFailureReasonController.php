<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\CreateFailureReason\CreateFailureReason;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Presentation\Http\Concerns\ResolvesFailureReasonView;
use Logistics\Execution\Presentation\Http\Request\CreateFailureReasonRequest;

/**
 * POST /app/admin/failure-reasons — alta de un motivo del catálogo. Un code repetido responde 409.
 */
final class CreateFailureReasonController extends ExecutionController
{
    use ResolvesFailureReasonView;

    public function __invoke(CreateFailureReasonRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $label = (string) $data['label'];
        $code = isset($data['code']) && is_string($data['code']) && trim($data['code']) !== ''
            ? trim($data['code'])
            : null;

        try {
            $this->commandBus->dispatch(new CreateFailureReason(
                label: $label,
                code: $code,
                active: (bool) ($data['active'] ?? true),
                requiresPhoto: (bool) ($data['requires_photo'] ?? false),
                requiresNote: (bool) ($data['requires_note'] ?? false),
                noteLabel: isset($data['note_label']) && is_string($data['note_label']) ? $data['note_label'] : null,
                sortOrder: (int) ($data['sort_order'] ?? 0),
            ));
        } catch (ExecutionException $exception) {
            // code repetido = conflicto del recurso (decisión del dueño: 409, no 422).
            return $this->problem('conflict', $exception->getMessage(), 409);
        }

        // El code final puede haberse derivado de la etiqueta: se recalcula igual que el dominio para devolver
        // el recurso recién creado.
        $finalCode = FailureReasonCode::fromString($code ?? $label)->value;

        return new JsonResponse(['data' => $this->failureReasonByCode($this->queryBus, $finalCode)], 201);
    }
}
