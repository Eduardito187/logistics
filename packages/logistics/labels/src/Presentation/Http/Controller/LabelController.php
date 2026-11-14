<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Logistics\Labels\Application\Command\EmitOrderLabels\EmitOrderLabels;
use Logistics\Labels\Application\Command\EmitShipmentLabels\EmitShipmentLabels;
use Logistics\Labels\Application\Query\GetDispatchLines\GetDispatchLines;
use Logistics\Labels\Application\Query\GetShipmentLabels\GetShipmentLabels;
use Logistics\Labels\Application\Query\ResolveLabelUnit\ResolveLabelUnit;
use Logistics\Labels\Application\ReadModel\DispatchLinesView;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Presentation\Http\Request\EmitShipmentLabelsRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

/**
 * Etiquetas de un pedido para la app interna (puerta /api/v1/app/*).
 *
 * Solo habla con los buses CQRS; nunca con Infrastructure ni con el dominio. La respuesta es
 * offline-friendly: cada etiqueta trae su `qr_content` (el JSON firmado exacto del QR) y su `zpl`
 * listo para la Zebra, de modo que la app pueda cachearlos e imprimir sin conexión.
 */
final readonly class LabelController
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * Etiquetas ya emitidas de un pedido (reimpresión / sincronización offline de la app).
     */
    public function index(string $orderNumber): JsonResponse
    {
        return new JsonResponse(['data' => $this->labelsFor($orderNumber)]);
    }

    /**
     * Líneas de despacho del pedido para la pantalla de etiquetado por producto: cada producto con nombre,
     * imagen, origen/destino humanos, su envío (para agrupar multishipping), si está autorizada y el estado
     * de su etiqueta. Envelope: { order_reference, destinations[], data[] }.
     */
    public function dispatchLines(string $orderNumber): JsonResponse
    {
        $view = $this->queryBus->ask(new GetDispatchLines($orderNumber));
        assert($view instanceof DispatchLinesView);

        return new JsonResponse($view->toArray());
    }

    /**
     * FALLBACK ONLINE: resuelve una etiqueta escaneada (su id/ULID) al bulto físico + envío. El flujo normal
     * es offline-first (la app mapea label_id→unit_id desde lo que ya cacheó: EmittedLabel / UnitTracking);
     * este endpoint es solo para una etiqueta que la app no tenga cacheada.
     */
    public function resolveUnit(string $labelId): JsonResponse
    {
        $view = $this->queryBus->ask(new ResolveLabelUnit($labelId));
        assert($view instanceof LabelUnitView);

        return new JsonResponse([...$view->toArray(), ...$this->customerOf($view->shipmentId)]);
    }

    /**
     * Cliente y tienda destino del envío de la etiqueta, para que quien la escanea vea a quién se entrega y
     * si el pedido es de otra tienda aunque no lo tenga cargado. Se piden al contexto shipments por el
     * QueryBus. Las claves van SIEMPRE (null si la etiqueta no resolvió o el envío no está disponible).
     *
     * @return array{customer_name: string|null, customer_document_id: string|null, customer_phone: string|null, destination_node_id: string|null}
     */
    private function customerOf(?string $shipmentId): array
    {
        $empty = ['customer_name' => null, 'customer_document_id' => null, 'customer_phone' => null, 'destination_node_id' => null];
        if ($shipmentId === null || $shipmentId === '') {
            return $empty;
        }

        try {
            $shipment = $this->queryBus->ask(new FindShipment($shipmentId));
        } catch (\Throwable) {
            return $empty;
        }

        if (!$shipment instanceof ShipmentView) {
            return $empty;
        }

        return [
            'customer_name' => $shipment->customerName !== '' ? $shipment->customerName : null,
            'customer_document_id' => $shipment->customerDocumentId,
            'customer_phone' => $shipment->customerPhone,
            'destination_node_id' => $shipment->destinationNodeId,
        ];
    }

    /**
     * Emite las etiquetas del pedido y devuelve el resultado ya firmado y renderizado.
     *
     * Dos modos sobre el mismo endpoint:
     *  - LOTE EXPLÍCITO (origen + destino + líneas en el cuerpo): emite ese lote tal cual (EmitShipmentLabels).
     *  - POR ORDEN (cuerpo vacío, o solo `origin` como escape hatch): el backend deriva origen/destino/líneas
     *    de los envíos del pedido y del plan publicado, y emite SOLO lo autorizado y aún pending, de forma
     *    idempotente/parcial. Siempre responde 201 con la colección completa de etiquetas del pedido.
     */
    public function store(EmitShipmentLabelsRequest $request, string $orderNumber): JsonResponse
    {
        try {
            if ($request->hasExplicitBatch()) {
                $this->commandBus->dispatch(new EmitShipmentLabels(
                    orderNumber: $orderNumber,
                    origin: $request->origin(),
                    destination: $request->destination(),
                    lines: $request->orderLines(),
                ));
            } else {
                $this->commandBus->dispatch(new EmitOrderLabels(
                    orderNumber: $orderNumber,
                    originOverride: $request->origin() !== '' ? $request->origin() : null,
                ));
            }
        } catch (DuplicateLabelException $e) {
            // El `id` de una etiqueta es único por unidad física: reemitir es un conflicto duro (ADR 004).
            // Formato de error ÚNICO (decisión del dueño): el motivo viaja en `detail` y en `error.message`.
            $code = 'label_already_emitted';

            return new JsonResponse([
                'type' => rtrim((string) config('api.problem_type_base_uri', '/problems'), '/').'/'.$code,
                'title' => 'Label already emitted',
                'status' => Response::HTTP_CONFLICT,
                'code' => $code,
                'detail' => $e->getMessage(),
                'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], Response::HTTP_CONFLICT, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['data' => $this->labelsFor($orderNumber)], Response::HTTP_CREATED);
    }

    /**
     * ZPL concatenado de las etiquetas del pedido, listo para enviar a la impresora Zebra. Las etiquetas
     * son PERMANENTES: no se revocan ni se re-emiten. Content-Type `application/x-zpl` (texto plano).
     */
    public function zpl(string $orderNumber): Response
    {
        $views = $this->views($orderNumber);
        if ($views === []) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $zpl = implode("\n", array_map(static fn (EmittedLabelView $view): string => $view->zpl, $views));

        return new Response($zpl, Response::HTTP_OK, ['Content-Type' => 'application/x-zpl']);
    }

    /**
     * Vistas de lectura de las etiquetas de un pedido, tal como las devuelve el QueryBus.
     *
     * @return list<EmittedLabelView>
     */
    private function views(string $orderNumber): array
    {
        /** @var list<EmittedLabelView> $views */
        $views = $this->queryBus->ask(new GetShipmentLabels($orderNumber));

        return $views;
    }

    /**
     * Etiquetas de un pedido en forma primitiva para el envelope JSON.
     *
     * @return list<array{order_number: string, label_id: string, key_id: int, sku: string, line_number: int, qr_content: string, zpl: string, emitted_at: int}>
     */
    private function labelsFor(string $orderNumber): array
    {
        return array_map(
            static fn (EmittedLabelView $view): array => $view->toArray(),
            $this->views($orderNumber),
        );
    }
}
