<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\FleetDocumentMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;

/**
 * Implementación Eloquent del repositorio de documentos de flota. `save` es un upsert por id.
 */
final readonly class EloquentDocumentRepository implements DocumentRepository
{
    public function __construct(
        private FleetDocumentMapper $mapper = new FleetDocumentMapper,
    ) {}

    public function nextIdentity(): FleetDocumentId
    {
        return FleetDocumentId::generate();
    }

    public function save(FleetDocument $document): void
    {
        $row = $this->mapper->toRow($document);
        FleetDocumentModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(FleetDocumentId $id): ?FleetDocument
    {
        $model = FleetDocumentModel::query()->find($id->toString());

        return $model instanceof FleetDocumentModel ? $this->mapper->toDomain($model) : null;
    }

    public function all(): array
    {
        return array_values(FleetDocumentModel::query()
            ->orderBy('expires_on')
            ->get()
            ->map(fn (FleetDocumentModel $model): FleetDocument => $this->mapper->toDomain($model))
            ->all());
    }
}
