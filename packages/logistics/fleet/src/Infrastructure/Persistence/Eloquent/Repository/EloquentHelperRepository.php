<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\HelperMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;

/**
 * Implementación Eloquent del repositorio de ayudantes. `save` es un upsert por id.
 */
final readonly class EloquentHelperRepository implements HelperRepository
{
    public function __construct(
        private HelperMapper $mapper = new HelperMapper,
    ) {}

    public function nextIdentity(): HelperId
    {
        return HelperId::generate();
    }

    public function save(Helper $helper): void
    {
        $row = $this->mapper->toRow($helper);
        HelperModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(HelperId $id): ?Helper
    {
        $model = HelperModel::query()->find($id->toString());

        return $model instanceof HelperModel ? $this->mapper->toDomain($model) : null;
    }

    public function all(): array
    {
        return array_values(HelperModel::query()
            ->orderBy('name')
            ->get()
            ->map(fn (HelperModel $model): Helper => $this->mapper->toDomain($model))
            ->all());
    }
}
