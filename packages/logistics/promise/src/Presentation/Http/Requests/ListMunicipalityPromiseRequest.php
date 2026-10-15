<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los parámetros de consulta de la promesa de entrega del cliente. El destino se identifica por
 * una de tres vías: un municipio, una coordenada (lat+lng juntas) o un envío. La autorización por
 * credencial de canal la resuelve el middleware; aquí solo se valida la forma.
 */
final class ListMunicipalityPromiseRequest extends FormRequest
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
            'municipality_id' => ['sometimes', 'string', 'max:64'],
            'shipment_id' => ['sometimes', 'string', 'uuid'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
            'horizon_days' => ['sometimes', 'integer', 'between:1,60'],
        ];
    }

    /**
     * Exige al menos una vía de destino: municipio, coordenada o envío. Sin ninguna no hay dónde prometer.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasMunicipality = $this->filled('municipality_id');
            $hasPoint = $this->filled('latitude') && $this->filled('longitude');
            $hasShipment = $this->filled('shipment_id');

            if (!$hasMunicipality && !$hasPoint && !$hasShipment) {
                $validator->errors()->add(
                    'municipality_id',
                    'Provide a municipality, a coordinate (latitude and longitude), or a shipment to quote the delivery promise.',
                );
            }
        });
    }
}
