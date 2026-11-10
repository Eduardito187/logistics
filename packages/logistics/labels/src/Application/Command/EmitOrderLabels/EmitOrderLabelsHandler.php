<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\EmitOrderLabels;

use Logistics\Labels\Application\Contract\OrderLabelEmissionPlanReader;
use Logistics\Labels\Application\Service\LabelBatchEmitter;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Emite las etiquetas de un pedido derivando todo del pedido (emisión por orden).
 *
 * Recorre el plan de emisión (un grupo por envío hermano) y emite SOLO lo autorizado (parada en plan
 * publicado) y aún PENDING; salta los grupos ya emitidos. Así es idempotente y parcial: reabrir la
 * pantalla emite lo que falta sin duplicar bultos. Todos los hermanos se emiten bajo la referencia de la
 * orden (el `dst` del QR los distingue), con la misma marca de tiempo de emisión.
 *
 * @implements CommandHandler<EmitOrderLabels>
 */
final readonly class EmitOrderLabelsHandler implements CommandHandler
{
    public function __construct(
        private OrderLabelEmissionPlanReader $plan,
        private LabelBatchEmitter $emitter,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof EmitOrderLabels);

        $issuedAt = $this->clock->now()->getTimestamp();

        foreach ($this->plan->emissionPlan($command->orderNumber) as $group) {
            // Solo lo autorizado y aún pending; lo ya emitido se salta (idempotente/parcial).
            if (!$group->authorized || $group->alreadyEmitted || $group->lines === []) {
                continue;
            }

            $origin = $command->originOverride ?? $group->origin;
            // Sin un origen resoluble no se puede firmar un QR válido (el `org` es obligatorio): se omite
            // el grupo en vez de romper todo el lote.
            if ($origin === '') {
                continue;
            }

            $this->emitter->emit(
                orderNumber: $command->orderNumber,
                origin: $origin,
                destination: $group->destination,
                lines: $group->lines,
                issuedAt: $issuedAt,
                preEmitted: false,
            );
        }
    }
}
