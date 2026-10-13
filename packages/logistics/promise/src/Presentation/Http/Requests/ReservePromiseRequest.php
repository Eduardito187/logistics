<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el cuerpo para reservar el cupo de una promesa ya cotizada: la ventana de reserva en minutos.
 *
 * Se acota el TTL a un día para evitar reservas que inmovilicen cupo indefinidamente.
 */
final class ReservePromiseRequest extends FormRequest
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
            'ttl_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
