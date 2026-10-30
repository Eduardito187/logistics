<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del identificador de orden que llega por la ruta para resolver su factura.
 *
 * El id de orden es un UUID genérico (invoicing lo referencia sin depender del contexto orders): se valida
 * su forma aquí para rechazar ids malformados con un 422 limpio.
 */
final class GetInvoiceByOrderRequest extends FormRequest
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
            'orderId' => ['required', 'uuid'],
        ];
    }

    /**
     * Incorpora el id de la ruta al conjunto de datos validados (no viaja en el cuerpo).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge(['orderId' => $this->route('orderId')], parent::validationData());
    }

    /**
     * Identificador de orden ya validado como UUID.
     */
    public function orderId(): string
    {
        return (string) $this->validated()['orderId'];
    }
}
