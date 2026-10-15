<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el cuerpo para cotizar una promesa de entrega: envío, zona y la franja pedida (fecha + etiqueta).
 *
 * La franja viaja en primitivos (fecha ISO Y-m-d + etiqueta de franja) tal como la espera el comando
 * QuotePromise; el handler revalida contra el proveedor de disponibilidad que la franja siga con cupo.
 */
final class QuotePromiseRequest extends FormRequest
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
            'shipment_ref' => ['required', 'string', 'max:64'],
            'zone_id' => ['required', 'string', 'max:64'],
            'slot_date' => ['required', 'date_format:Y-m-d'],
            'time_slot' => ['required', 'string', 'max:32'],
        ];
    }
}
