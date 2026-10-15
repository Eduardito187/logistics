<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación para definir una geocerca: nombre, tipo del catálogo y polígono (lista de puntos).
 *
 * El polígono llega como lista de pares {lat,lng} (mínimo 3 vértices); el handler lo convierte al VO
 * Polygon del dominio. `node_ref` es opcional (vincula la geocerca con un nodo de red).
 */
final class DefineGeofenceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:distribution_center,store,authorized_zone,forbidden_zone,rest_area,fuel_station,customer'],
            'polygon' => ['required', 'array', 'min:3'],
            'polygon.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'polygon.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'node_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
