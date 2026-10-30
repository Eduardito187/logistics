<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del identificador de factura que llega por la ruta (no por el cuerpo).
 *
 * La identidad es un UUID v7 (`InvoiceId`): se valida su forma aquí para rechazar ids malformados con un
 * 422 limpio en vez de dejar que reviente el value object. La autenticación de la puerta la resuelve el
 * middleware del integrador. La usan el detalle de factura y el render del documento (mismo parámetro).
 */
final class GetInvoiceRequest extends FormRequest
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
            'id' => ['required', 'uuid'],
        ];
    }

    /**
     * Incorpora el id de la ruta al conjunto de datos validados (no viaja en el cuerpo).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge(['id' => $this->route('id')], parent::validationData());
    }

    /**
     * Identificador de factura ya validado como UUID.
     */
    public function invoiceId(): string
    {
        return (string) $this->validated()['id'];
    }
}
