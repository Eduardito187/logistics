<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\ChecklistTemplate;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateId;

/**
 * Puerto de persistencia de plantillas del checklist pre-salida. La implementación vive en Infrastructure.
 */
interface ChecklistTemplateRepository
{
    public function nextIdentity(): ChecklistTemplateId;

    public function save(ChecklistTemplate $template): void;

    public function findById(ChecklistTemplateId $id): ?ChecklistTemplate;

    /**
     * Plantilla activa que aplica a un tipo de vehículo: la específica del tipo si existe; si no (o si
     * `$vehicleType` es null), la plantilla por defecto (tipo null). Entre varias activas gana la más
     * reciente. Null si no hay ninguna activa.
     */
    public function activeFor(?string $vehicleType): ?ChecklistTemplate;
}
