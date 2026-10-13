<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

/**
 * Ingesta de pings desde el app interno (puerta app): el origen es 'app'.
 */
final class RecordAppVehiclePingsController extends AbstractRecordVehiclePingsController
{
    protected function source(): string
    {
        return 'app';
    }
}
