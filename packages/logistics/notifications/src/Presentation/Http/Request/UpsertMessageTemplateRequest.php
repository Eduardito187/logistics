<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Validación para crear o actualizar una plantilla de mensaje (P1-NOT-08). Única por tipo, canal y
 * locale. El asunto es opcional (solo lo usa email); el cuerpo es obligatorio.
 */
final class UpsertMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message_type' => ['required', Rule::enum(MessageType::class)],
            'channel' => ['required', Rule::enum(Channel::class)],
            'locale' => ['nullable', 'string', 'max:8'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ];
    }
}
