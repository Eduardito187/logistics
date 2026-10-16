<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Execution\Domain\ValueObject\DeliveryIssueType;

/**
 * Validación del reporte de inconveniente de entrega del chofer (puerta app). El tipo debe ser un valor
 * del dominio. El GPS y la foto llegan del dispositivo (opcionales). `occurred_at` es obligatorio: el
 * hecho ocurrió en terreno en un momento concreto (la app lo sella, incluso offline).
 */
final class ReportDeliveryIssueRequest extends FormRequest
{
    /**
     * La autorización por puerta (token usuario + dispositivo) la aplica el middleware que monta el
     * integrador; aquí no se decide acceso.
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
        $types = array_map(static fn (DeliveryIssueType $t): string => $t->value, DeliveryIssueType::cases());

        return [
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'occurred_at' => ['required', 'date'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'device_id' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'string', 'max:128'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
