<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la asignación de camión de reparto a un pedido (POST /app/orders/{n}/vehicle).
 *
 * Solo exige el id del vehículo; que exista y esté operativo lo valida el controller contra fleet
 * (422 si no). La autorización por puerta (token + dispositivo + rol) la aplica el middleware del gate.
 */
final class AssignOrderVehicleRequest extends FormRequest
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
            'vehicle_id' => ['required', 'string', 'uuid'],
        ];
    }
}
