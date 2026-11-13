<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la apertura manual de un caso de compensación (call center / supervisor). El disparador y
 * el alcance se validan contra los valores del dominio; la base puede ser declarada (`manual_base_cents`
 * + `manual_lines`) o resolverse del envío. Las reglas de negocio (mínimo, topes, cuatro ojos) las aplica
 * el dominio después.
 */
final class OpenCompensationCaseRequest extends FormRequest
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
            'trigger' => ['required', 'string', 'in:DELIVERY_FAILED,LATE_DELIVERY,DAMAGED_PRODUCT,INCOMPLETE,LOW_RATING,MANUAL'],
            'scope' => ['required', 'string', 'in:LINE,SHIPMENT,ORDER'],
            'shipment_id' => ['sometimes', 'nullable', 'uuid'],
            'order_reference' => ['sometimes', 'nullable', 'string', 'max:191'],
            'source_event_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:191'],
            'affected_skus' => ['sometimes', 'array'],
            'affected_skus.*' => ['string', 'max:191'],
            'manual_base_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'manual_lines' => ['sometimes', 'array'],
            'manual_lines.*.product_name' => ['required_with:manual_lines', 'string', 'max:191'],
            'manual_lines.*.sku' => ['required_with:manual_lines', 'string', 'max:191'],
            'manual_lines.*.affected_quantity' => ['sometimes', 'integer', 'min:1'],
            'manual_lines.*.unit_price_cents' => ['sometimes', 'integer', 'min:0'],
            'proposed_by' => ['sometimes', 'nullable', 'string', 'max:191'],
            'ownership' => ['sometimes', 'nullable', 'string', 'max:64'],
            'driver_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'origin_node_id' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
