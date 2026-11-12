<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\DefineChecklistTemplate;

use Logistics\Fleet\Domain\Contract\ChecklistTemplateRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\Model\ChecklistTemplate;
use Logistics\Fleet\Domain\ValueObject\ChecklistItemCriticality;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateId;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateItem;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Alta o reemplazo de una plantilla del checklist; persiste y publica su evento al outbox en la misma
 * transacción (la abre el decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<DefineChecklistTemplate>
 */
final readonly class DefineChecklistTemplateHandler implements CommandHandler
{
    public function __construct(
        private ChecklistTemplateRepository $templates,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineChecklistTemplate);

        $items = array_values(array_map(
            static function (array $item): ChecklistTemplateItem {
                $criticality = ChecklistItemCriticality::tryFrom($item['criticality']);
                if ($criticality === null) {
                    throw FleetException::because("Criticidad inválida en el ítem {$item['key']}: {$item['criticality']}.");
                }

                return ChecklistTemplateItem::of(
                    $item['key'],
                    $item['label'],
                    $item['category'],
                    $criticality,
                    (bool) ($item['requires_photo'] ?? false),
                );
            },
            $command->items,
        ));

        if ($command->templateId === null) {
            $template = ChecklistTemplate::define(
                $this->templates->nextIdentity(),
                $command->name,
                $command->vehicleType,
                $items,
                $command->active,
            );
        } else {
            $template = $this->templates->findById(ChecklistTemplateId::fromString($command->templateId));
            if ($template === null) {
                throw FleetException::because("No existe la plantilla de checklist {$command->templateId}.");
            }
            $template->redefine($command->name, $command->vehicleType, $items, $command->active);
        }

        $this->templates->save($template);

        $this->recordEvents->record($template->releaseEvents(), 'fleet.checklist_template');
    }
}
