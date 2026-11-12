<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del inicio/cierre de la ruta del día (POST /app/vehicles/{id}/route/start|close). Odómetro y
 * GPS opcionales; `route_date` opcional (si falta, el día de `occurred_at`).
 */
final class RouteLifecycleRequest extends FormRequest
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
            'occurred_at' => ['required', 'date'],
            'route_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'odometer_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ];
    }
}
