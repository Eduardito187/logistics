<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Coverage;

use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Promise\Domain\Contract\CoverageProfileProvider;
use Logistics\Promise\Domain\ValueObject\ServiceWindow;
use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;

/**
 * Implementación del puerto de perfil de zona apoyada en el contexto de cobertura.
 *
 * Lee la fila de la zona (coverage_zones) y traduce su jsonb de reglas —días de servicio, tiempo base y
 * ventanas horarias— al VO ZoneServiceProfile del dominio de promise. El cruce es de solo lectura y por
 * primitivos: promise no agrega dependencia composer a coverage (patrón del monorepo), solo lee su Record
 * para no exponer ni duplicar su esquema. Si la zona no existe o está inactiva, no hay cobertura: null.
 */
final class CoverageBackedProfileProvider implements CoverageProfileProvider
{
    public function profileFor(string $zoneId): ?ZoneServiceProfile
    {
        $record = ZoneRecord::query()->whereKey($zoneId)->first();
        if ($record === null || $record->active !== true) {
            return null;
        }

        /** @var array<string, mixed> $rules */
        $rules = $record->rules;

        return ZoneServiceProfile::of(
            $this->serviceDays($rules),
            $this->leadMinutes($rules),
            $this->windows($rules),
        );
    }

    /**
     * @param  array<string, mixed> $rules
     * @return list<int>
     */
    private function serviceDays(array $rules): array
    {
        $days = [];
        $raw = $rules['serviceDays'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $day) {
                if (is_numeric($day)) {
                    $day = (int) $day;
                    if ($day >= 1 && $day <= 7) {
                        $days[] = $day;
                    }
                }
            }
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function leadMinutes(array $rules): int
    {
        $raw = $rules['baseMinutes'] ?? 0;

        return is_numeric($raw) && (int) $raw >= 0 ? (int) $raw : 0;
    }

    /**
     * @param  array<string, mixed> $rules
     * @return list<ServiceWindow>
     */
    private function windows(array $rules): array
    {
        $windows = [];
        $raw = $rules['timeWindows'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $window) {
                if (is_array($window)
                    && isset($window['start'], $window['end'])
                    && is_string($window['start'])
                    && is_string($window['end'])) {
                    $windows[] = ServiceWindow::fromClock($window['start'], $window['end']);
                }
            }
        }

        return $windows;
    }
}
