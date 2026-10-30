<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Request;

use Commerce\Invoicing\Application\Command\CancelInvoice\CancelInvoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la anulación de una factura: el id llega por la ruta (no hay cuerpo).
 *
 * Se valida la forma del UUID antes de despachar el comando. La anulación es idempotente en el dominio
 * (`Invoice::cancel()` no vuelve a grabar si ya estaba anulada).
 */
final class CancelInvoiceRequest extends FormRequest
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
    protected function validationData(): array
    {
        return array_merge(['id' => $this->route('id')], parent::validationData());
    }

    /**
     * Arma el comando de Application a partir del id de factura validado.
     */
    public function toCommand(): CancelInvoice
    {
        /** @var string $id */
        $id = $this->validated()['id'];

        return new CancelInvoice(invoiceId: $id);
    }
}
