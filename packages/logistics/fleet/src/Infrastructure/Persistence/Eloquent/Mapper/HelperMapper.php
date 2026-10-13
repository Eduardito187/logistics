<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;

/**
 * Traduce entre el agregado Helper (dominio) y su fila Eloquent.
 */
final class HelperMapper
{
    public function toDomain(HelperModel $model): Helper
    {
        $documents = array_map(
            static fn (array $doc): DocumentExpiry => DocumentExpiry::of(
                $doc['type'],
                new DateTimeImmutable($doc['expires_on']),
            ),
            $model->documents,
        );

        return Helper::reconstitute(
            HelperId::fromString($model->id),
            $model->name,
            $model->phone,
            array_values($documents),
            $model->active,
        );
    }

    /**
     * @return array{id: string, name: string, phone: string|null, documents: list<array{type: string, expires_on: string}>, active: bool}
     */
    public function toRow(Helper $helper): array
    {
        return [
            'id' => $helper->id()->toString(),
            'name' => $helper->name(),
            'phone' => $helper->phone(),
            'documents' => array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $helper->documents())),
            'active' => $helper->isActive(),
        ];
    }
}
