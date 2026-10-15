<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\HelperAvailabilityChanged;
use Logistics\Fleet\Domain\Event\HelperProfileUpdated;
use Logistics\Fleet\Domain\Event\HelperRegistered;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Domain\ValueObject\HelperProfile;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Ayudante de la flota: personal que acompaña al chofer en la carga, descarga y entrega.
 *
 * No conduce (eso es del chofer), pero sí tiene documentos con vencimiento (carné de salud, EPP, etc.)
 * que la flota debe vigilar. Una tripulación referencia a sus ayudantes por id (ver Crew). El teléfono
 * es opcional porque no todo ayudante tiene uno registrado; es su teléfono primario.
 *
 * Además lleva datos personales opcionales (foto, CI, fecha de nacimiento, teléfono secundario,
 * dirección, contacto de emergencia) y referencias personales, que se completan por separado con
 * `updateProfile()`. Son nullable porque el perfil se enriquece de a poco.
 */
final class Helper extends AggregateRoot
{
    /**
     * @param list<DocumentExpiry>    $documents
     * @param list<PersonalReference> $references
     */
    private function __construct(
        private readonly HelperId $id,
        private readonly string $name,
        private readonly ?string $phone,
        private array $documents,
        private bool $active,
        private ?string $photoPath = null,
        private ?BirthDate $birthDate = null,
        private ?NationalId $nationalId = null,
        private ?PhoneNumber $phoneSecondary = null,
        private ?string $address = null,
        private ?string $emergencyContactName = null,
        private ?PhoneNumber $emergencyContactPhone = null,
        private array $references = [],
    ) {}

    /**
     * Registra un ayudante nuevo. Arranca activo salvo que se indique lo contrario.
     *
     * Los datos personales son opcionales y se pueden completar luego con `updateProfile()`.
     *
     * @param list<DocumentExpiry>    $documents
     * @param list<PersonalReference> $references
     */
    public static function register(
        HelperId $id,
        string $name,
        ?string $phone = null,
        array $documents = [],
        bool $active = true,
        ?string $photoPath = null,
        ?BirthDate $birthDate = null,
        ?NationalId $nationalId = null,
        ?PhoneNumber $phoneSecondary = null,
        ?string $address = null,
        ?string $emergencyContactName = null,
        ?PhoneNumber $emergencyContactPhone = null,
        array $references = [],
    ): self {
        $helper = new self(
            $id,
            $name,
            $phone,
            array_values($documents),
            $active,
            $photoPath,
            $birthDate,
            $nationalId,
            $phoneSecondary,
            $address,
            $emergencyContactName,
            $emergencyContactPhone,
            array_values($references),
        );
        $helper->recordEvent(new HelperRegistered($id->toString(), $name, $phone, $active));

        return $helper;
    }

    /**
     * Reconstituye un ayudante desde persistencia, sin grabar eventos.
     *
     * @param list<DocumentExpiry>    $documents
     * @param list<PersonalReference> $references
     */
    public static function reconstitute(
        HelperId $id,
        string $name,
        ?string $phone,
        array $documents,
        bool $active,
        ?string $photoPath = null,
        ?BirthDate $birthDate = null,
        ?NationalId $nationalId = null,
        ?PhoneNumber $phoneSecondary = null,
        ?string $address = null,
        ?string $emergencyContactName = null,
        ?PhoneNumber $emergencyContactPhone = null,
        array $references = [],
    ): self {
        return new self(
            $id,
            $name,
            $phone,
            array_values($documents),
            $active,
            $photoPath,
            $birthDate,
            $nationalId,
            $phoneSecondary,
            $address,
            $emergencyContactName,
            $emergencyContactPhone,
            array_values($references),
        );
    }

    /**
     * Cambia la disponibilidad. Idempotente: si ya está en ese estado, no hace nada ni graba evento.
     */
    public function changeAvailability(bool $active): void
    {
        if ($this->active === $active) {
            return;
        }
        $this->active = $active;
        $this->recordEvent(new HelperAvailabilityChanged($this->id->toString(), $active));
    }

    public function addDocument(DocumentExpiry $document): void
    {
        $this->documents[] = $document;
    }

    /**
     * Reemplaza por completo los datos personales del ayudante y graba el evento de perfil actualizado.
     */
    public function updateProfile(HelperProfile $profile): void
    {
        $this->photoPath = $profile->photoPath;
        $this->birthDate = $profile->birthDate;
        $this->nationalId = $profile->nationalId;
        $this->phoneSecondary = $profile->phoneSecondary;
        $this->address = $profile->address;
        $this->emergencyContactName = $profile->emergencyContactName;
        $this->emergencyContactPhone = $profile->emergencyContactPhone;
        $this->references = array_values($profile->references);

        $this->recordEvent(new HelperProfileUpdated($this->id->toString(), $profile->toArray()));
    }

    /**
     * Documentos del ayudante que vencen dentro de $days días contados desde $ref (incluye vencidos).
     *
     * @return list<DocumentExpiry>
     */
    public function documentsExpiringWithin(int $days, DateTimeImmutable $ref): array
    {
        return array_values(array_filter(
            $this->documents,
            static fn (DocumentExpiry $document): bool => $document->expiresWithin($days, $ref),
        ));
    }

    public function id(): HelperId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    /**
     * @return list<DocumentExpiry>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function photoPath(): ?string
    {
        return $this->photoPath;
    }

    public function birthDate(): ?BirthDate
    {
        return $this->birthDate;
    }

    public function nationalId(): ?NationalId
    {
        return $this->nationalId;
    }

    public function phoneSecondary(): ?PhoneNumber
    {
        return $this->phoneSecondary;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function emergencyContactName(): ?string
    {
        return $this->emergencyContactName;
    }

    public function emergencyContactPhone(): ?PhoneNumber
    {
        return $this->emergencyContactPhone;
    }

    /**
     * @return list<PersonalReference>
     */
    public function references(): array
    {
        return $this->references;
    }
}
