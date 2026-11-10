<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Agrupa los datos personales editables de un ayudante (foto, CI, fecha de nacimiento, teléfono
 * secundario, dirección, contacto de emergencia) y sus referencias personales.
 *
 * Es el argumento de `Helper::updateProfile()`. El teléfono primario del ayudante es el `phone` que ya
 * lleva el agregado (no se toca acá). Las referencias se reemplazan por completo en cada actualización.
 */
final readonly class HelperProfile
{
    /**
     * @param list<PersonalReference> $references
     */
    public function __construct(
        public ?string $photoPath = null,
        public ?BirthDate $birthDate = null,
        public ?NationalId $nationalId = null,
        public ?PhoneNumber $phoneSecondary = null,
        public ?string $address = null,
        public ?string $emergencyContactName = null,
        public ?PhoneNumber $emergencyContactPhone = null,
        public array $references = [],
    ) {}

    /**
     * Núcleo de datos personales comunes a chofer/ayudante. En el ayudante el perfil ES exactamente ese
     * núcleo (no hay datos adicionales); se expone como VO compartido con el chofer para no duplicar la forma.
     */
    public function personal(): PersonalProfile
    {
        return new PersonalProfile(
            $this->photoPath,
            $this->birthDate,
            $this->nationalId,
            $this->phoneSecondary,
            $this->address,
            $this->emergencyContactName,
            $this->emergencyContactPhone,
            $this->references,
        );
    }

    /**
     * Forma primitiva para persistencia y eventos (nunca se serializa el objeto de dominio).
     *
     * @return array{
     *     photo_path: string|null,
     *     birth_date: string|null,
     *     national_id: string|null,
     *     phone_secondary: string|null,
     *     address: string|null,
     *     emergency_contact_name: string|null,
     *     emergency_contact_phone: string|null,
     *     references: list<array{name: string, relationship: string|null, phone: string|null, note: string|null}>
     * }
     */
    public function toArray(): array
    {
        // El perfil del ayudante ES el núcleo personal común: delega para no duplicar la forma primitiva.
        return $this->personal()->toArray();
    }
}
