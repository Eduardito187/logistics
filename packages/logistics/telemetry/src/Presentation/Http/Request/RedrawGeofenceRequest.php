<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación para redibujar el polígono de una geocerca: la nueva lista de vértices {lat,lng}.
 */
final class RedrawGeofenceRequest extends FormRequest
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
            'polygon' => ['required', 'array', 'min:3'],
            'polygon.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'polygon.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
