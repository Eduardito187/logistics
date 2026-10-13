<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;
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
            $model->photo_path,
            $model->birth_date !== null ? BirthDate::of(new DateTimeImmutable($model->birth_date)) : null,
            $model->national_id !== null ? NationalId::of($model->national_id) : null,
            $model->phone_secondary !== null ? PhoneNumber::of($model->phone_secondary) : null,
            $model->address,
            $model->emergency_contact_name,
            $model->emergency_contact_phone !== null ? PhoneNumber::of($model->emergency_contact_phone) : null,
            self::referencesToDomain($model->references),
        );
    }

    /**
     * @return array{
     *     id: string, name: string, phone: string|null,
     *     documents: list<array{type: string, expires_on: string}>, active: bool, photo_path: string|null,
     *     birth_date: string|null, national_id: string|null, phone_secondary: string|null,
     *     address: string|null, emergency_contact_name: string|null, emergency_contact_phone: string|null,
     *     references: list<array{name: string, relationship: string|null, phone: string|null, note: string|null}>
     * }
     */
    public function toRow(Helper $helper): array
    {
        return [
            'id' => $helper->id()->toString(),
            'name' => $helper->name(),
            'phone' => $helper->phone(),
            'documents' => array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $helper->documents())),
            'active' => $helper->isActive(),
            'photo_path' => $helper->photoPath(),
            'birth_date' => $helper->birthDate()?->toString(),
            'national_id' => $helper->nationalId()?->value,
            'phone_secondary' => $helper->phoneSecondary()?->value,
            'address' => $helper->address(),
            'emergency_contact_name' => $helper->emergencyContactName(),
            'emergency_contact_phone' => $helper->emergencyContactPhone()?->value,
            'references' => array_values(array_map(
                static fn (PersonalReference $reference): array => $reference->toArray(),
                $helper->references(),
            )),
        ];
    }

    /**
     * Convierte las filas jsonb de referencias en VOs del dominio.
     *
     * @param  list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}> $references
     * @return list<PersonalReference>
     */
    private static function referencesToDomain(array $references): array
    {
        return array_values(array_map(
            static fn (array $reference): PersonalReference => PersonalReference::of(
                $reference['name'],
                $reference['relationship'] ?? null,
                $reference['phone'] ?? null,
                $reference['note'] ?? null,
            ),
            $references,
        ));
    }
}
