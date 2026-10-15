<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el cuerpo para reservar una franja de despacho para un pedido: el pedido, la zona, la fecha y
 * la ventana horaria (inicio/fin en HH:MM). La autorización por credencial de canal la resuelve el
 * middleware; aquí solo se valida la forma.
 */
final class ReserveDeliverySlotRequest extends FormRequest
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
            'order_id' => ['required', 'string', 'uuid'],
            'zone_id' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date_format:Y-m-d'],
            'window_start' => ['required', 'date_format:H:i'],
            // El orden (inicio < fin) lo garantiza el dominio (DeliverySlot); aquí solo se valida el formato.
            'window_end' => ['required', 'date_format:H:i'],
        ];
    }
}
