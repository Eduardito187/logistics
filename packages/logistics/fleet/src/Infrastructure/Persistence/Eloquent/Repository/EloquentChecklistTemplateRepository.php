<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\ChecklistTemplateRepository;
use Logistics\Fleet\Domain\Model\ChecklistTemplate;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\ChecklistTemplateMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\ChecklistTemplateModel;

/**
 * Implementación Eloquent del repositorio de plantillas del checklist. `save` es un upsert por id.
 */
final readonly class EloquentChecklistTemplateRepository implements ChecklistTemplateRepository
{
    public function __construct(
        private ChecklistTemplateMapper $mapper = new ChecklistTemplateMapper,
    ) {}

    public function nextIdentity(): ChecklistTemplateId
    {
        return ChecklistTemplateId::generate();
    }

    public function save(ChecklistTemplate $template): void
    {
        $row = $this->mapper->toRow($template);
        ChecklistTemplateModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(ChecklistTemplateId $id): ?ChecklistTemplate
    {
        $model = ChecklistTemplateModel::query()->find($id->toString());

        return $model instanceof ChecklistTemplateModel ? $this->mapper->toDomain($model) : null;
    }

    public function activeFor(?string $vehicleType): ?ChecklistTemplate
    {
        $type = $vehicleType !== null ? strtolower(trim($vehicleType)) : '';

        if ($type !== '') {
            $specific = $this->latestActive($type);
            if ($specific instanceof ChecklistTemplate) {
                return $specific;
            }
        }

        return $this->latestActive(null);
    }

    /**
     * Plantilla activa más reciente de un tipo (null = la por defecto).
     */
    private function latestActive(?string $vehicleType): ?ChecklistTemplate
    {
        $query = ChecklistTemplateModel::query()->where('active', true);
        if ($vehicleType === null) {
            $query->whereNull('vehicle_type');
        } else {
            $query->where('vehicle_type', $vehicleType);
        }

        $model = $query->orderByDesc('updated_at')->orderByDesc('id')->first();

        return $model instanceof ChecklistTemplateModel ? $this->mapper->toDomain($model) : null;
    }
}
