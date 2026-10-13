<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de validación de un escaneo en terreno.
 *
 * Reúne los campos comunes a todos los escaneos (GPS, dispositivo/usuario, hora e idempotencia);
 * cada escaneo concreto añade sus propios campos en rules().
 */
abstract class ScanRequest extends FormRequest
{
    /**
     * La autorización por puerta (token usuario + dispositivo, roles) la aplica el middleware que
     * monta el integrador; aquí no se decide acceso.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas comunes a todo escaneo. device_id/user_id/idempotency_key se aceptan opcionales porque
     * pueden llegar por header o inyectados desde el token de Auth.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'shipment_id' => ['required', 'uuid'],
            'unit_id' => ['required', 'string', 'max:64'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'occurred_at' => ['required', 'date'],
            'device_id' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'string', 'max:128'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
