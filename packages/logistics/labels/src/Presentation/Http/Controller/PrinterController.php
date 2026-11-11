<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Controller;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Labels\Application\Query\ListAvailablePrinters\ListAvailablePrinters;
use Logistics\Labels\Application\ReadModel\PrinterView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta app: impresoras de red registradas que la app ofrece en "Impresoras de tu sede".
 *
 * Alcance server-side (no se confía en filtros del cliente):
 * - supervisor/admin: todas las impresoras activas;
 * - resto: las de su nodo asignado (`users.node_id`) + las compartidas (sin nodo).
 */
final readonly class PrinterController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $allNodes = $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
        $nodeId = $user instanceof User && is_string($user->node_id) && $user->node_id !== '' ? $user->node_id : null;

        /** @var list<PrinterView> $printers */
        $printers = $this->queryBus->ask(new ListAvailablePrinters(nodeId: $nodeId, allNodes: $allNodes));

        return new JsonResponse([
            'data' => array_map(static fn (PrinterView $view): array => $view->toArray(), $printers),
        ]);
    }
}
