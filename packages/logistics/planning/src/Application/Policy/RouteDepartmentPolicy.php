<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Policy;

use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\AreaLocation;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;

/**
 * Política geográfica de rutas a clientes (invariante de negocio).
 *
 * Regla: todas las paradas de CLIENTE de un camión deben estar en el MISMO departamento; si están en
 * distintos municipios del mismo departamento, la ruta es intermunicipal (permitida). No existe la
 * entrega a clientes multidepartamental. Los traspasos CD→CD y las paradas sin cliente no se pasan a
 * esta política (están exentos): quien llama solo incluye paradas de cliente.
 *
 * Se apoya en el puerto de solo lectura DepartmentLocator del contexto Cobertura para resolver el
 * departamento de cada coordenada. Es tolerante: una parada cuya ubicación no se puede determinar
 * (fuera de cobertura) no bloquea ni fija el departamento de la ruta.
 */
final readonly class RouteDepartmentPolicy
{
    public function __construct(private DepartmentLocator $locator) {}

    /**
     * Valida que todas las paradas de cliente compartan departamento y resuelve el alcance de la ruta.
     * Lanza RouteSpansMultipleDepartments si dos paradas caen en departamentos distintos.
     *
     * @param list<ClientStopLocation> $clientStops
     */
    public function guard(array $clientStops): RouteDepartmentScope
    {
        $department = null;
        /** @var array<string, true> $cityIds */
        $cityIds = [];

        foreach ($clientStops as $stop) {
            $location = $this->locator->locate(GeoPoint::of($stop->latitude, $stop->longitude));
            if ($location === null) {
                // Tolerante: ubicación desconocida (fuera de cobertura) no participa de la regla.
                continue;
            }

            if ($department === null) {
                $department = $location;
            } elseif (!$location->sameDepartmentAs($department)) {
                throw RouteSpansMultipleDepartments::between(
                    $department->departmentName,
                    $location->departmentName,
                );
            }

            if ($location->cityId !== null) {
                $cityIds[$location->cityId] = true;
            }
        }

        if (!$department instanceof AreaLocation) {
            return RouteDepartmentScope::unknown();
        }

        return new RouteDepartmentScope(
            $department->departmentId,
            $department->departmentName,
            count($cityIds) > 1,
        );
    }
}
