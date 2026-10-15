<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Puerto de encolado de la activación por rango. El dominio/aplicación pide "activá estos cupos para este
 * municipio y rango"; la infraestructura lo encola como job asíncrono, troceado por lotes, fuera del
 * request del usuario (principio 4). Mantener este puerto permite extraer capacity a un microservicio
 * cambiando solo el transporte (cola local → red) sin tocar el handler.
 */
interface CapacityActivationDispatcher
{
    /**
     * Encola la generación de cupos de un municipio en el rango [from, to], para los días de la semana
     * activos y las ventanas dadas.
     *
     * @param list<int>              $weekdays
     * @param list<ActivationWindow> $windows
     */
    public function dispatch(
        string $municipalityId,
        SlotDate $from,
        SlotDate $to,
        array $weekdays,
        array $windows,
    ): void;
}
