<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el cuerpo para que el cliente reserve una franja de despacho para su envío: el envío, el día y
 * la ventana (inicio/fin en HH:MM). El municipio se resuelve del envío; el canal puede pasar uno (o una
 * coordenada) explícitos. La autorización por credencial de canal la resuelve el middleware.
 */
final class ReserveMunicipalitySlotRequest extends FormRequest
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
            'shipment_id' => ['required', 'string', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'window_start' => ['required', 'date_format:H:i'],
            // El orden (inicio < fin) lo garantiza el dominio (DeliverySlot/TimeSlot); aquí solo el formato.
            'window_end' => ['required', 'date_format:H:i'],
            'municipality_id' => ['sometimes', 'string', 'max:64'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    /**
     * Si el canal envía coordenada, exige lat y lng juntas (ya lo cubre required_with, se refuerza aquí
     * para un mensaje claro). El municipio puede omitirse: se resuelve del envío.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('latitude') xor $this->filled('longitude')) {
                $validator->errors()->add('latitude', 'Latitude and longitude must be provided together.');
            }
        });
    }
}
