<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Validación para configurar la cascada de un tipo de mensaje (P1-NOT-01). Exige al menos un canal y
 * una condición de escalado válida; el orden de los canales es el orden de la cascada.
 */
final class SetMessageTypeRuleRequest extends FormRequest
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
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::enum(Channel::class)],
            'escalate_when' => ['required', Rule::enum(EscalateCondition::class)],
        ];
    }
}
