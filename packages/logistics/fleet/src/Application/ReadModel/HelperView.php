<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;

/**
 * Proyección de lectura de un ayudante para listados y API. Primitivos: no expone objetos de dominio.
 *
 * Incluye los datos personales (aditivo): foto, CI, fecha de nacimiento con la edad ya calculada,
 * teléfono secundario, dirección, contacto de emergencia y referencias. El `phone` es el teléfono primario.
 */
final readonly class HelperView
{
    /**
     * @param list<array{type: string, expires_on: string}>                                               $documents
     * @param list<array{name: string, relationship: string|null, phone: string|null, note: string|null}> $references
     */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $phone,
        public array $documents,
        public bool $active,
        public ?string $photoPath,
        public ?string $birthDate,
        public ?int $age,
        public ?string $nationalId,
        public ?string $phoneSecondary,
        public ?string $address,
        public ?string $emergencyContactName,
        public ?string $emergencyContactPhone,
        public array $references,
    ) {}

    public static function fromDomain(Helper $helper): self
    {
        $birthDate = $helper->birthDate();

        return new self(
            $helper->id()->toString(),
            $helper->name(),
            $helper->phone(),
            array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $helper->documents())),
            $helper->isActive(),
            $helper->photoPath(),
            $birthDate?->toString(),
            $birthDate?->ageAt(new DateTimeImmutable),
            $helper->nationalId()?->value,
            $helper->phoneSecondary()?->value,
            $helper->address(),
            $helper->emergencyContactName(),
            $helper->emergencyContactPhone()?->value,
            array_values(array_map(
                static fn (PersonalReference $reference): array => $reference->toArray(),
                $helper->references(),
            )),
        );
    }

    /**
     * @return array{
     *     id: string, name: string, phone: string|null,
     *     documents: list<array{type: string, expires_on: string}>, active: bool, photo_path: string|null,
     *     birth_date: string|null, age: int|null, national_id: string|null, phone_secondary: string|null,
     *     address: string|null, emergency_contact_name: string|null, emergency_contact_phone: string|null,
     *     references: list<array{name: string, relationship: string|null, phone: string|null, note: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'documents' => $this->documents,
            'active' => $this->active,
            'photo_path' => $this->photoPath,
            'birth_date' => $this->birthDate,
            'age' => $this->age,
            'national_id' => $this->nationalId,
            'phone_secondary' => $this->phoneSecondary,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergencyContactName,
            'emergency_contact_phone' => $this->emergencyContactPhone,
            'references' => $this->references,
        ];
    }
}
