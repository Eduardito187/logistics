<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Query\ListUsers\ListUsers;
use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista los usuarios como proyecciones de lectura (consulta ListUsers).
 *
 * Filtros opcionales: `status`, `role` (clave de rol) y `search` (nombre o email). Paginación estándar
 * opt-in (auditoría app #20): con `page`/`per_page` o `cursor` responde `data` + `meta` (ver ApiPagination);
 * sin parámetros de página conserva la respuesta histórica (lista completa, sin `meta`).
 */
final class ListUsersController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'role' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->guard(function () use ($request): JsonResponse {
            $paginate = ApiPagination::requested($request);

            $result = $this->queries->ask(new ListUsers(
                status: $this->filter($request, 'status'),
                role: $this->filter($request, 'role'),
                search: $this->filter($request, 'search'),
                limit: $paginate ? ApiPagination::limit($request) : null,
                offset: $paginate ? ApiPagination::offset($request) : 0,
            ));

            if ($result instanceof Page) {
                /** @var Page<UserView> $result */
                $items = array_map(static fn (UserView $view): array => $view->toArray(), $result->items);

                return new JsonResponse(ApiPagination::envelope($items, $result->total, $request));
            }

            /** @var list<UserView> $users */
            $users = $result;

            return $this->data(
                array_map(static fn (UserView $view): array => $view->toArray(), $users),
            );
        });
    }

    /**
     * Filtro de texto de la query string: null si falta o viene vacío.
     */
    private function filter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
