<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

/**
 * Instrucción que devuelve TripTracker para un ping entrante: qué hacer con el trip activo.
 */
enum TripAction
{
    /** No hay trip activo: abrir uno nuevo en este ping. */
    case StartNew;

    /** El ping cae dentro del trip activo: agregarlo como un punto más. */
    case Continue;

    /** Pasó el hueco temporal: cerrar el trip activo y abrir uno nuevo en este ping. */
    case CloseAndStartNew;
}
