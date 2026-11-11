<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cockpit;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Labels\Application\Query\ResolveLabelUnit\ResolveLabelUnit;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Resuelve una etiqueta ESCANEADA desde la WEB (scanner del admin), aceptando CUALQUIERA de sus
 * simbologías:
 *   - el QR firmado (JSON) → se extrae el id de la etiqueta del payload firmado;
 *   - el código de barras Zebra (Code128) o el código único → es el id de la etiqueta directo.
 *
 * Ambos terminan en el MISMO `label_id` y se resuelven por el read-side (ResolveLabelUnit), que devuelve
 * el bulto físico (pedido, SKU, unidad/pieza). Mismo-origen, solo oficina. Sin UUID crudo en la respuesta
 * más allá de los ids que el front necesita para enlazar (no se muestran al usuario).
 */
final class LabelScanController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->hasAnyRole(Role::Admin, Role::Supervisor)) {
            abort(403);
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['resolved' => false, 'error' => 'empty'], 422);
        }

        $labelId = $this->labelIdFrom($code);

        try {
            /** @var LabelUnitView $view */
            $view = $this->queryBus->ask(new ResolveLabelUnit($labelId));
        } catch (Throwable) {
            return response()->json(['resolved' => false, 'label_id' => $labelId]);
        }

        return response()->json($view->toArray());
    }

    /**
     * Extrae el id de etiqueta del valor escaneado: si es el QR firmado (JSON), lo parsea y toma el id del
     * payload; si no, el valor ES el id de la etiqueta (Code128 / código único). Tolerante.
     */
    private function labelIdFrom(string $code): string
    {
        // Heurística: el QR firmado es un JSON; un Code128/código único no empieza con '{'.
        if (str_starts_with($code, '{')) {
            try {
                return SignedLabel::fromQrContent($code)->payload->id;
            } catch (Throwable) {
                // No era un QR firmado válido: se usa el texto crudo como id de etiqueta.
            }
        }

        return $code;
    }
}
