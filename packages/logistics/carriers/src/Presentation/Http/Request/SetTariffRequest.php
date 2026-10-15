<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para definir la tarifa de un transportista en un corredor y clase de vehículo.
 *
 * El corredor llega por sus tres primitivos y el precio como centavos + moneda, igual que el Command
 * SetTariff; el handler los convierte a los VO del dominio.
 */
final class SetTariffRequest extends FormRequest
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
            'corridor_from' => ['required', 'string', 'max:255'],
            'corridor_to' => ['required', 'string', 'max:255'],
            'corridor_type' => ['required', 'string', 'in:cd_to_customer,cd_to_cd,cd_to_store,store_to_store,store_to_cd,store_pickup'],
            'vehicle_class' => ['required', 'string', 'in:trailer,truck,third_party'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
