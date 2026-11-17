<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\UpdateFailureReason\UpdateFailureReason;
use Logistics\Execution\Presentation\Http\Concerns\ResolvesFailureReasonView;
use Logistics\Execution\Presentation\Http\Request\UpdateFailureReasonRequest;

/**
 * PUT /app/admin/failure-reasons/{code} — edita un motivo del catálogo por su code (slug, no UUID). El code
 * es inmutable. Un motivo inexistente lo rechaza el dominio (422). Edición parcial.
 */
final class UpdateFailureReasonController extends ExecutionController
{
    use ResolvesFailureReasonView;

    public function __invoke(UpdateFailureReasonRequest $request, string $code): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new UpdateFailureReason(
            code: $code,
            label: isset($data['label']) && is_string($data['label']) ? $data['label'] : null,
            active: array_key_exists('active', $data) ? (bool) $data['active'] : null,
            requiresPhoto: array_key_exists('requires_photo', $data) ? (bool) $data['requires_photo'] : null,
            requiresNote: array_key_exists('requires_note', $data) ? (bool) $data['requires_note'] : null,
            noteLabel: array_key_exists('note_label', $data) && is_string($data['note_label']) ? $data['note_label'] : null,
            sortOrder: array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : null,
        ));

        return new JsonResponse(['data' => $this->failureReasonByCode($this->queryBus, $code)]);
    }
}
