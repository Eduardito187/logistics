<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Fleet\Domain\Model\ChecklistTemplate;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateId;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateItem;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\ChecklistTemplateModel;

/**
 * Traduce entre el agregado ChecklistTemplate (dominio) y su fila Eloquent.
 */
final class ChecklistTemplateMapper
{
    public function toDomain(ChecklistTemplateModel $model): ChecklistTemplate
    {
        return ChecklistTemplate::reconstitute(
            ChecklistTemplateId::fromString($model->id),
            $model->name,
            $model->vehicle_type,
            $model->active,
            array_values(array_map(
                static fn (array $item): ChecklistTemplateItem => ChecklistTemplateItem::fromArray($item),
                $model->items,
            )),
        );
    }

    /**
     * @return array{id: string, name: string, vehicle_type: string|null, active: bool, items: list<array{key: string, label: string, category: string, criticality: string, requires_photo: bool}>}
     */
    public function toRow(ChecklistTemplate $template): array
    {
        return [
            'id' => $template->id()->toString(),
            'name' => $template->name(),
            'vehicle_type' => $template->vehicleType(),
            'active' => $template->isActive(),
            'items' => array_values(array_map(
                static fn (ChecklistTemplateItem $item): array => $item->toArray(),
                $template->items(),
            )),
        ];
    }
}
