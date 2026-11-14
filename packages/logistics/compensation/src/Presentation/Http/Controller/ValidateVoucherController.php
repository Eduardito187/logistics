<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Compensation\Application\Query\ValidateVoucher\ValidateVoucher;
use Logistics\Compensation\Application\ReadModel\VoucherValidityView;
use Logistics\Compensation\Presentation\Http\ApiController;
use Logistics\Compensation\Presentation\Http\Request\ValidateVoucherRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * POST /integrations/vouchers/validate — ¿es canjeable el vale, por cuánto y hasta cuándo? (POS/checkout).
 *
 * Siempre responde 200 con la proyección de validez: un código inválido, inexistente o de otro titular
 * devuelve `valid:false` + razón genérica (no se revela información a quien prueba códigos — mitigación de
 * fraude; esa decisión ya la toma la consulta).
 */
final class ValidateVoucherController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(ValidateVoucherRequest $request): JsonResponse
    {
        $email = $request->filled('customer_email') ? (string) $request->string('customer_email') : null;

        $view = $this->queries->ask(new ValidateVoucher(
            code: (string) $request->string('code'),
            customerEmail: $email,
        ));
        assert($view instanceof VoucherValidityView);

        return $this->data($view->toArray());
    }
}
