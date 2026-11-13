<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Compensation\Application\Query\GetCompensationCase\GetCompensationCase;
use Logistics\Compensation\Application\ReadModel\CompensationCaseView;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * GET /integrations/compensation/cases/{caseId} — devuelve un caso de compensación por su id.
 *
 * 200 con la proyección de lectura (montos legibles, líneas con nombre + SKU, traza de cálculo); 404 si no
 * existe o el id está mal formado.
 */
final class GetCompensationCaseController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request, string $caseId): JsonResponse
    {
        try {
            $view = $this->queries->ask(new GetCompensationCase(caseId: $caseId));
        } catch (CompensationException) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'Compensation case not found.')->toResponse($request);
        }

        if (!$view instanceof CompensationCaseView) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'Compensation case not found.')->toResponse($request);
        }

        return $this->data($view->toArray());
    }
}
