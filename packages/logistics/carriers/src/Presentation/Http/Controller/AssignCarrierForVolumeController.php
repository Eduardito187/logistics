<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\AssignCarrierForVolume\AssignCarrierForVolume;
use Logistics\Carriers\Application\ReadModel\AssignmentView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve la asignación de un corredor por volumen: la clase de vehículo que corresponde y la tarifa
 * más barata disponible para esa clase (consulta AssignCarrierForVolume, usada por planning para
 * consolidar entre departamentos). El volumen llega en m³.
 */
final class AssignCarrierForVolumeController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var AssignmentView $assignment */
            $assignment = $this->queries->ask(new AssignCarrierForVolume(
                corridorFrom: (string) $request->query('corridor_from', ''),
                corridorTo: (string) $request->query('corridor_to', ''),
                corridorType: (string) $request->query('corridor_type', ''),
                volumeM3: (float) $request->query('volume_m3', '0'),
            ));

            return $this->data($assignment->toArray());
        });
    }
}
