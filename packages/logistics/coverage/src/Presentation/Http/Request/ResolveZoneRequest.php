<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el punto (lat, lng) de la consulta punto→zona. Acepta los parámetros por query string.
 */
final class ResolveZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización por puerta (token de usuario/dispositivo o credencial de servicio) la
        // aplica el middleware que monta el integrador; acá solo se valida la forma del input.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function lat(): float
    {
        return (float) $this->validated('lat');
    }

    public function lng(): float
    {
        return (float) $this->validated('lng');
    }
}
