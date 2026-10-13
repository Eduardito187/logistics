<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\ReadModel;

use Logistics\Coverage\Domain\ValueObject\AreaLocation;

/**
 * Modelo de lectura de la ubicación administrativa de un punto: toda la cadena resuelta (departamento,
 * provincia, municipio y ciudad) en forma plana y serializable, para respuestas de API y consola.
 */
final readonly class LocatedAreaView
{
    public function __construct(
        public string $departmentId,
        public ?string $departmentName,
        public ?string $provinceId,
        public ?string $provinceName,
        public ?string $municipalityId,
        public ?string $municipalityName,
        public ?string $cityId,
        public ?string $cityName,
    ) {}

    public static function fromLocation(AreaLocation $location): self
    {
        return new self(
            $location->departmentId,
            $location->departmentName,
            $location->provinceId,
            $location->provinceName,
            $location->municipalityId,
            $location->municipalityName,
            $location->cityId,
            $location->cityName,
        );
    }

    /**
     * @return array{
     *     departmentId: string,
     *     departmentName: string|null,
     *     provinceId: string|null,
     *     provinceName: string|null,
     *     municipalityId: string|null,
     *     municipalityName: string|null,
     *     cityId: string|null,
     *     cityName: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'departmentId' => $this->departmentId,
            'departmentName' => $this->departmentName,
            'provinceId' => $this->provinceId,
            'provinceName' => $this->provinceName,
            'municipalityId' => $this->municipalityId,
            'municipalityName' => $this->municipalityName,
            'cityId' => $this->cityId,
            'cityName' => $this->cityName,
        ];
    }
}
