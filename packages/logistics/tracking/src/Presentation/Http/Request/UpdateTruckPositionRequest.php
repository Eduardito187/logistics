<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la ingesta de posición GPS del camión.
 *
 * Solo valida FORMA y RANGO de los primitivos (coordenadas y timestamp ISO 8601); la regla de negocio
 * (descartar lecturas viejas, estado terminal, etc.) vive en el dominio, no acá. La autorización la
 * resuelve el middleware de la puerta, por eso authorize() devuelve true.
 */
final class UpdateTruckPositionRequest extends FormRequest
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
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            // Instante del reporte en ISO 8601 (p. ej. 2026-10-13T14:03:00-04:00). Se acepta cualquier
            // variante válida; el handler arma el DateTimeImmutable y el dominio valida la regla.
            'reported_at' => ['required', 'string', 'date'],
        ];
    }

    public function latitude(): float
    {
        return (float) $this->input('lat');
    }

    public function longitude(): float
    {
        return (float) $this->input('lng');
    }

    public function reportedAt(): string
    {
        return (string) $this->input('reported_at');
    }
}
