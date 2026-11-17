<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la ENTREGA A LA TRANSPORTADORA (handoff): NUESTRO lado dejó la carga en el almacén de la
 * transportadora (nodo `carrier_hub`). El envío (`shipmentId`) va en la URL; acá viajan el almacén de la
 * transportadora, la transportadora (opcional), la hora, la posición (opcional) y la clave de idempotencia.
 *
 * El actor (dispositivo + usuario) NO se valida acá: sale del token (ver ExecutionController). La clave de
 * idempotencia es un contrato offline-first: la app la manda, de preferencia, en el header `Idempotency-Key`;
 * prepareForValidation() la normaliza al cuerpo para exigirla por igual venga por header o por cuerpo.
 */
final class CarrierHandoffRequest extends FormRequest
{
    /**
     * La autorización por puerta (token usuario + dispositivo, roles) la aplica el middleware que monta el
     * integrador; aquí no se decide acceso.
     */
    public function authorize(): bool
    {
        return true;
    }

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'carrier_hub_node_id' => ['required', 'uuid'],
            'carrier_id' => ['sometimes', 'nullable', 'uuid'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            // La posición es informativa (la app la manda con el resto de los escaneos); 0,0 lo descarta el dominio.
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128'],
        ];
    }
}
