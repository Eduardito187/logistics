<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\ReprintOrderLabels;

use Logistics\Labels\Application\Contract\OrderLabelEmissionPlanReader;
use Logistics\Labels\Application\Service\LabelBatchEmitter;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Event\LabelsRevoked;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reimprime las etiquetas de un pedido: revoca las vigentes (publica LabelsRevoked) y emite un lote
 * nuevo con `id` nuevos (publica LabelsEmitted vía el emisor). Ambas operaciones corren dentro de la
 * misma transacción del CommandBus, de modo que nunca quede un pedido sin etiquetas válidas.
 *
 * La app reimprime SOLO con el motivo (sin líneas en el body). Por eso, si el command no trae líneas
 * explícitas, se RE-EMITE derivando todo del pedido (igual que la emisión por orden): se recorre el plan
 * de emisión y, como las vigentes ya se revocaron en esta misma transacción, el plan las ve revocadas y
 * vuelve a emitir cada grupo autorizado. Sin esto, reprint revocaba y emitía un lote VACÍO (0 etiquetas).
 *
 * @implements CommandHandler<ReprintOrderLabels>
 */
final readonly class ReprintOrderLabelsHandler implements CommandHandler
{
    public function __construct(
        private LabelBatchEmitter $emitter,
        private EmittedLabelRepository $repository,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
        private OrderLabelEmissionPlanReader $plan,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReprintOrderLabels);

        $now = $this->clock->now()->getTimestamp();

        $revoked = $this->repository->revokeForOrder($command->orderNumber, $now, $command->reason);
        if ($revoked !== []) {
            $this->recordEvents->record(
                [new LabelsRevoked($command->orderNumber, $revoked, $command->reason, $now)],
                'labels.order',
            );
        }

        // Con líneas explícitas en el body: se emite ese lote tal cual (compat). Sin líneas (caso de la app):
        // se re-emite derivando del pedido, para no dejarlo con 0 etiquetas tras revocar.
        if ($command->lines !== []) {
            $this->emitter->emit(
                orderNumber: $command->orderNumber,
                origin: $command->origin,
                destination: $command->destination,
                lines: $command->lines,
                issuedAt: $now,
                preEmitted: false,
            );

            return;
        }

        $originOverride = $command->origin !== '' ? $command->origin : null;
        foreach ($this->plan->emissionPlan($command->orderNumber) as $group) {
            // Solo lo autorizado y con líneas; lo ya revocado arriba ya no cuenta como emitido, así se re-emite.
            if (!$group->authorized || $group->alreadyEmitted || $group->lines === []) {
                continue;
            }
            $origin = $originOverride ?? $group->origin;
            if ($origin === '') {
                continue;
            }
            $this->emitter->emit(
                orderNumber: $command->orderNumber,
                origin: $origin,
                destination: $group->destination,
                lines: $group->lines,
                issuedAt: $now,
                preEmitted: false,
            );
        }
    }
}
