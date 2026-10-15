<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del descarte manual de un mensaje: exige un motivo (queda en last_error para auditoría).
 */
final class DiscardMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }
}
