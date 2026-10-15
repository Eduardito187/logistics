<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Query\ListIntegrationTokens\ListIntegrationTokens;
use Logistics\Access\Application\ReadModel\IntegrationTokenView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los tokens de integración como proyecciones de lectura, con filtro opcional por estado
 * (consulta ListIntegrationTokens). Nunca expone el secreto en claro, solo el prefijo público.
 */
final class ListIntegrationTokensController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var list<IntegrationTokenView> $tokens */
            $tokens = $this->queries->ask(new ListIntegrationTokens(status: $status));

            return $this->data(
                array_map(static fn (IntegrationTokenView $view): array => $view->toArray(), $tokens),
            );
        });
    }
}
