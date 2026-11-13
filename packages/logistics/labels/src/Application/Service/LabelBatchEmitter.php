<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Service;

use DateTimeImmutable;
use Logistics\Labels\Application\Contract\ProductMeasuresReader;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Contract\LabelRenderer;
use Logistics\Labels\Domain\Contract\LabelSigner;
use Logistics\Labels\Domain\Contract\LabelSizePolicyProvider;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Domain\Event\LabelsEmitted;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Labels\Domain\Service\LabelSizeResolver;
use Logistics\Labels\Domain\ValueObject\LabelSizePolicy;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Outbox\Application\RecordDomainEvents;

/**
 * Colaborador de aplicación que concentra el flujo de emisión de un lote de etiquetas, reutilizado por
 * los tres casos de uso de escritura (emisión por la app, pre-emisión al planificar y reimpresión).
 *
 * Pasos: arma los payloads (uno por bulto), los firma, ELIGE EL PERFIL DE TAMAÑO por producto (reglas por
 * SKU + medidas del catálogo, con degradado a Standard), renderiza su ZPL en ese perfil con el nombre del
 * producto, los persiste detectando duplicados y publica el evento LabelsEmitted al outbox. Todo corre
 * dentro de la transacción que abre el decorador transaccional del CommandBus; por eso aquí no hay nada del
 * framework.
 */
final readonly class LabelBatchEmitter
{
    /** DPI de referencia de la emisión (la Zebra ZQ630 Plus es 203 dpi). El perfil es en mm; el renderer
     *  calcula los dots con el dpi. La reimpresión a otra impresora puede re-renderizar a otro dpi. */
    private const int EMISSION_DPI = 203;

    public function __construct(
        private LabelBatchFactory $factory,
        private LabelSigner $signer,
        private LabelRenderer $renderer,
        private LabelSymbologyProvider $symbology,
        private LabelSizePolicyProvider $sizePolicy,
        private LabelSizeResolver $sizeResolver,
        private ProductMeasuresReader $measures,
        private EmittedLabelRepository $repository,
        private RecordDomainEvents $recordEvents,
    ) {}

    /**
     * Emite el lote de etiquetas de un pedido y devuelve los `labelId` emitidos, en orden.
     *
     * @param  list<OrderLineInput> $lines      líneas del pedido, en orden (la posición fija el número de línea)
     * @param  bool                 $preEmitted true si la emisión la dispara el planificador (worker), no la app
     * @return list<string>
     */
    public function emit(
        string $orderNumber,
        string $origin,
        string $destination,
        array $lines,
        int $issuedAt,
        bool $preEmitted,
    ): array {
        $keyId = $this->signer->activeKeyId();

        $at = (new DateTimeImmutable)->setTimestamp($issuedAt);

        // La simbología (QR, Code128 o ambos) y la política de tamaño son reglas versionadas; se resuelven a
        // la fecha de emisión del lote y se aplican a todas sus etiquetas, para que el pedido imprima parejo.
        $symbology = $this->symbology->current($at);
        $policy = $this->sizePolicy->current($at);

        // Mapa SKU → nombre del producto para el texto de la etiqueta (el nombre no viaja en el QR firmado).
        $namesBySku = [];
        foreach ($lines as $line) {
            $namesBySku[$line->sku] ??= $line->name;
        }

        $payloads = $this->factory->build(
            keyId: $keyId,
            orderNumber: $orderNumber,
            origin: $origin,
            destination: $destination,
            issuedAt: $issuedAt,
            lines: array_map(
                static fn (OrderLineInput $line): array => [
                    'sku' => $line->sku,
                    'quantity' => $line->quantity,
                    'piecesPerUnit' => $line->piecesPerUnit,
                ],
                array_values($lines),
            ),
        );

        // Cache del perfil por SKU: una sola lectura de medidas por producto, no por bulto.
        $profileBySku = [];

        $labelIds = [];
        foreach ($payloads as $payload) {
            $signed = $this->signer->sign($payload);

            $profile = $profileBySku[$payload->sku] ??= $this->resolveProfile($payload->sku, $policy);
            $productName = $namesBySku[$payload->sku] ?? null;

            $zpl = $this->renderer->render($signed, $symbology, $profile, self::EMISSION_DPI, $productName);

            $this->repository->add(EmittedLabel::of($orderNumber, $signed, $zpl, $issuedAt, $profile));
            $labelIds[] = $payload->id;
        }

        $this->recordEvents->record(
            [new LabelsEmitted($orderNumber, $keyId, $labelIds, $preEmitted, $issuedAt)],
            'labels.order',
        );

        return $labelIds;
    }

    /**
     * Elige el perfil de tamaño del producto: reglas por SKU + medidas del catálogo (si las hay), con
     * degradado a Standard. La lectura de medidas es tolerante (null si el catálogo no está).
     */
    private function resolveProfile(string $sku, LabelSizePolicy $policy): LabelSizeProfile
    {
        return $this->sizeResolver->resolve(
            sku: $sku,
            measures: $this->measures->measuresFor($sku),
            policy: $policy,
        );
    }
}
