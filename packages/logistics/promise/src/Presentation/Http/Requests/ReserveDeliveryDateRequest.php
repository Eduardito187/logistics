<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el cuerpo de la reserva por FECHA (Módulo 1): el cliente eligió SOLO el día y el sistema le asigna
 * la ventana. Lleva el envío, la fecha y, opcional, su preferencia horaria (`kind` + `from`/`to` en HH:MM).
 * El municipio se resuelve del envío; la autorización por credencial de canal la resuelve el middleware.
 */
final class ReserveDeliveryDateRequest extends FormRequest
{
    /** Formas de preferencia que acepta el canal (las valida luego el VO CustomerTimePreference). */
    private const PREFERENCE_KINDS = 'none,morning,afternoon,after,before,between';

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
            'shipment_id' => ['required', 'string', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'preference' => ['sometimes', 'array'],
            'preference.kind' => ['sometimes', 'string', 'in:'.self::PREFERENCE_KINDS],
            // Bordes horarios de las formas after/before/between; el VO valida la combinación y el orden.
            'preference.from' => ['sometimes', 'nullable', 'date_format:H:i'],
            'preference.to' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
