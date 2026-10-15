<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\ReadModel;

use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Modelo de lectura de la regla de cobertura vigente de una zona: forma plana y serializable (JSON /
 * consola / admin), sin exponer el VO de dominio.
 */
final readonly class ZoneRuleView
{
    /**
     * @param array<int, string>                            $services
     * @param array<string, int>                            $productLimits
     * @param array<int, array{start: string, end: string}> $timeWindows
     * @param array<int, int>                               $serviceDays
     */
    public function __construct(
        public string $zoneId,
        public ?string $nodeId,
        public array $services,
        public int $baseMinutes,
        public array $productLimits,
        public array $timeWindows,
        public array $serviceDays,
    ) {}

    public static function fromRules(string $zoneId, ZoneRules $rules): self
    {
        $primitive = $rules->toArray();

        return new self(
            $zoneId,
            $primitive['nodeId'],
            $primitive['services'],
            $primitive['baseMinutes'],
            $primitive['productLimits'],
            $primitive['timeWindows'],
            $primitive['serviceDays'],
        );
    }

    /**
     * @return array{
     *     zoneId: string,
     *     nodeId: string|null,
     *     services: array<int, string>,
     *     baseMinutes: int,
     *     productLimits: array<string, int>,
     *     timeWindows: array<int, array{start: string, end: string}>,
     *     serviceDays: array<int, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'zoneId' => $this->zoneId,
            'nodeId' => $this->nodeId,
            'services' => $this->services,
            'baseMinutes' => $this->baseMinutes,
            'productLimits' => $this->productLimits,
            'timeWindows' => $this->timeWindows,
            'serviceDays' => $this->serviceDays,
        ];
    }
}
