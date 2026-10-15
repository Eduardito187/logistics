<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\IncidentType;

/**
 * Validación del reporte de incidente del chofer (puerta app). La gravedad y el tipo deben ser valores
 * del dominio. El GPS llega del dispositivo (opcional), la ruta se resuelve sola si no se envía.
 */
final class ReportIncidentRequest extends FormRequest
{
    /**
     * La autorización por puerta (token usuario + dispositivo, roles) la aplica el middleware que monta
     * el integrador; aquí no se decide acceso.
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
        $severities = array_map(static fn (IncidentSeverity $s): string => $s->value, IncidentSeverity::cases());
        $types = array_map(static fn (IncidentType $t): string => $t->value, IncidentType::cases());

        return [
            'severity' => ['required', 'string', 'in:'.implode(',', $severities)],
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'route_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            'device_id' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'string', 'max:128'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
