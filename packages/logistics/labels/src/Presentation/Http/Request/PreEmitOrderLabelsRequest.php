<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Request;

/**
 * Validación de la pre-emisión de etiquetas (puerta integrations).
 *
 * Misma forma que la emisión por la app: origen, destino y líneas del pedido. La dispara el worker/
 * integrador al publicarse un plan; el handler es idempotente si el pedido ya tiene etiquetas.
 */
final class PreEmitOrderLabelsRequest extends EmitShipmentLabelsRequest {}
