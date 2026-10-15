<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

/**
 * Ingesta de pings desde proveedores GPS externos (puerta integrations): el origen es 'integration'.
 */
final class RecordIntegrationVehiclePingsController extends AbstractRecordVehiclePingsController
{
    protected function source(): string
    {
        return 'integration';
    }
}
