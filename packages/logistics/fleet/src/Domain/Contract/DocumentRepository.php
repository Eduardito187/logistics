<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;

/**
 * Puerto de persistencia de documentos de flota. La implementación vive en Infrastructure.
 */
interface DocumentRepository
{
    public function nextIdentity(): FleetDocumentId;

    public function save(FleetDocument $document): void;

    public function findById(FleetDocumentId $id): ?FleetDocument;

    /**
     * @return list<FleetDocument>
     */
    public function all(): array;
}
