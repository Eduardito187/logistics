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
     * La clave de idempotencia es un contrato OFFLINE-FIRST: la app la genera por escaneo y la manda,
     * de preferencia, en el header `Idempotency-Key`. Para que la validación la exija por igual venga
     * por header o por cuerpo, aquí se resuelve a una sola clave recortada (cuerpo primero, luego
     * header) y se fija en el cuerpo. Si no hay ninguna, se deja vacía para que `required` la rechace:
     * así no se cuela una clave en blanco, que colisionaría en el índice único de la base.
     */
    protected function prepareForValidation(): void
    {
        $body = $this->input('idempotency_key');
        $key = is_string($body) ? trim($body) : '';

        if ($key === '') {
            $header = $this->header('Idempotency-Key');
            $key = is_string($header) ? trim($header) : '';
        }

        $this->merge(['idempotency_key' => $key]);
    }

    /**
     * Reglas comunes a todo escaneo. device_id/user_id se aceptan opcionales porque pueden llegar
     * inyectados desde el token de Auth. La clave de idempotencia es OBLIGATORIA (header o cuerpo): sin
     * ella no hay forma de deduplicar el reenvío offline, y una clave vacía colisionaría en el índice
     * único de la base. prepareForValidation() ya normaliza el header al cuerpo.
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
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128'],
        ];
    }
}
