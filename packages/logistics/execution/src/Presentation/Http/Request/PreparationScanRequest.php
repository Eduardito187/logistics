<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación del escaneo de preparación (EAN → etiqueta → carril).
 *
 * Añade el vehículo: si la entrega del envío aún no existe, se abre con este vehículo.
 */
final class PreparationScanRequest extends ScanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'vehicle_id' => ['required', 'uuid'],
        ]);
    }
}
