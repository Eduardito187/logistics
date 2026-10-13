<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\ReadModel;

use Logistics\Coverage\Domain\ValueObject\AreaLocation;

/**
 * Modelo de lectura de la ubicación administrativa de un punto: departamento y municipio/ciudad en
 * forma plana y serializable, para respuestas de API y consola.
 */
final readonly class LocatedAreaView
{
    public function __construct(
        public string $departmentId,
        public ?string $departmentName,
        public ?string $cityId,
        public ?string $cityName,
    ) {}

    public static function fromLocation(AreaLocation $location): self
    {
        return new self(
            $location->departmentId,
            $location->departmentName,
            $location->cityId,
            $location->cityName,
        );
    }

    /**
     * @return array{departmentId: string, departmentName: string|null, cityId: string|null, cityName: string|null}
     */
    public function toArray(): array
    {
        return [
            'departmentId' => $this->departmentId,
            'departmentName' => $this->departmentName,
            'cityId' => $this->cityId,
            'cityName' => $this->cityName,
        ];
    }
}
