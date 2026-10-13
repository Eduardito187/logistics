<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanDate;

/**
 * Puerto de solo lectura que entrega los pedidos PENDIENTES a planificar para un día, ya con la carga
 * (kg/m3) estimada, el departamento resuelto y la franja elegida por el cliente. El dominio define QUÉ
 * necesita; la implementación (que lee el contexto de Envíos y Cobertura) vive en Infrastructure.
 *
 * Planificación no conoce el detalle de otros contextos: solo este contrato en primitivos de dominio.
 */
interface PendingDeliveryProvider
{
    /**
     * Pedidos pendientes candidatos a planificar para la fecha dada. La implementación lee en lotes
     * (chunks) para escalar; el dominio recibe la lista ya materializada.
     *
     * @return list<DeliveryCandidate>
     */
    public function pendingFor(PlanDate $date): array;
}
