<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los parámetros de la consulta de DÍAS ofrecibles (Módulo 1): el canal muestra solo fechas y el
 * sistema elige luego la ventana. El destino se identifica por una coordenada (`lat`+`lng` juntas), un
 * municipio o un envío. El rango `from`/`to` (fechas Y-m-d) es opcional; sin él se usa hoy + horizonte por
 * defecto. La autorización por credencial de canal la resuelve el middleware; aquí solo la forma.
 */
final class ListDeliveryDatesRequest extends FormRequest
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
            'lat' => ['sometimes', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180', 'required_with:lat'],
            'municipality_id' => ['sometimes', 'string', 'max:64'],
            'shipment_id' => ['sometimes', 'string', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Exige al menos una vía de destino: coordenada, municipio o envío. Sin ninguna no hay dónde ofrecer.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPoint = $this->filled('lat') && $this->filled('lng');
            $hasMunicipality = $this->filled('municipality_id');
            $hasShipment = $this->filled('shipment_id');

            if (!$hasPoint && !$hasMunicipality && !$hasShipment) {
                $validator->errors()->add(
                    'lat',
                    'Provide a coordinate (lat and lng), a municipality, or a shipment to list delivery dates.',
                );
            }
        });
    }
}
