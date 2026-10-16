<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Validación de la solicitud para encolar una notificación de un envío.
 *
 * El canal primario (push FCM) va por el topic del envío y no necesita contacto; el email
 * y el teléfono son respaldos opcionales de la cascada (ADR 006). El tipo de mensaje se valida contra
 * el lenguaje ubicuo (enum MessageType), que es también lo que el handler reconstruye.
 */
final class QueueNotificationRequest extends FormRequest
{
    /**
     * La autorización de la puerta (credenciales de servicio) la resuelve el middleware del integrador.
     */
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
            'shipment_id' => ['required', 'uuid'],
            'message_type' => ['required', Rule::enum(MessageType::class)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
