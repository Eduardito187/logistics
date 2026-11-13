<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Application\Contract\ScanBatchLedger;
use Logistics\Execution\Presentation\Http\Request\FailedDeliveryRequest;
use Logistics\Execution\Presentation\Http\Request\LoadScanRequest;
use Logistics\Execution\Presentation\Http\Request\PreparationScanRequest;
use Logistics\Execution\Presentation\Http\Request\ProofOfDeliveryRequest;
use Logistics\Execution\Presentation\Http\Request\StopArrivalRequest;
use Logistics\Execution\Presentation\Http\Request\UnloadScanRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;
use Logistics\Execution\Presentation\Http\Support\StoreNodeScope;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Exception\DomainException;
use Throwable;

/**
 * POST /app/deliveries/scans/batch — drena la cola offline en UN request (puerta app).
 *
 * Cuerpo: `{items:[{idempotency_key, kind, occurred_at, payload}]}` (tope configurable, 200 por defecto).
 * Cada ítem se valida con las MISMAS reglas y se despacha por el MISMO comando que su endpoint individual
 * (preparation, load, unload, departure, arrival, proof_of_delivery, failed). Los ítems son independientes:
 * cada uno corre en su propia transacción (decorador del bus), y uno rechazado no frena al resto.
 *
 * Respuesta 207 Multi-Status con un resultado por ítem, en el mismo orden:
 *   - accepted  → se aplicó ahora;
 *   - duplicate → su clave ya se había aplicado (lote previo, endpoint individual o repetida en este lote);
 *   - rejected  → no se aplicó; trae `problem` RFC 7807 (validation_failed, business_rule_violation,
 *                 forbidden, server_error). Un rechazo NO bloquea la clave: reintentarla la reevalúa.
 *
 * La idempotencia por ítem se persiste (ScanBatchLedger) y se respalda en la clave única de cada escaneo.
 */
final class RecordScanBatchController extends ExecutionController
{
    /** Tipos de ítem aceptados (cada uno = un endpoint individual). */
    public const array KINDS = ['preparation', 'load', 'unload', 'departure', 'arrival', 'proof_of_delivery', 'failed'];

    private const int DEFAULT_MAX_ITEMS = 200;

    public function __construct(
        CommandBus $commandBus,
        QueryBus $queryBus,
        private readonly ScanBatchLedger $ledger,
        private readonly EvidenceStorage $evidenceStorage,
    ) {
        parent::__construct($commandBus, $queryBus);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $max = max(1, (int) config('logistics-execution.scan_batch.max_items', self::DEFAULT_MAX_ITEMS));

        /** @var array{items: list<array{idempotency_key: string, kind: string, occurred_at: string, payload?: array<string, mixed>|null}>} $body */
        $body = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.$max],
            'items.*' => ['required', 'array'],
            'items.*.idempotency_key' => ['required', 'string', 'min:1', 'max:128'],
            'items.*.kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'items.*.occurred_at' => ['required', 'date'],
            'items.*.payload' => ['sometimes', 'nullable', 'array'],
        ]);

        $results = [];
        $seen = [];
        $totals = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];

        foreach (array_values($body['items']) as $index => $item) {
            $key = trim($item['idempotency_key']);

            $result = isset($seen[$key]) || $this->ledger->wasAccepted($key)
                ? $this->outcome($index, $item, 'duplicate')
                : $this->process($index, $item, $key, $request);

            $seen[$key] = true;
            $status = $result['result'];
            if ($status === 'accepted' || $status === 'duplicate' || $status === 'rejected') {
                $totals[$status]++;
            }
            $results[] = $result;
        }

        return new JsonResponse([
            'data' => $results,
            'meta' => ['count' => count($results)] + $totals,
        ], 207);
    }

    /**
     * Valida y despacha un ítem; registra su resultado en el libro de idempotencia.
     *
     * @param  array{idempotency_key: string, kind: string, occurred_at: string, payload?: array<string, mixed>|null} $item
     * @return array<string, mixed>
     */
    private function process(int $index, array $item, string $key, Request $request): array
    {
        $kind = $item['kind'];
        $data = array_merge($item['payload'] ?? [], [
            'occurred_at' => $item['occurred_at'],
            'idempotency_key' => $key,
        ]);
        $shipmentId = null;

        try {
            $validator = Validator::make($data, $this->rulesFor($kind, $request));
            if ($kind === 'proof_of_delivery') {
                // Igual que el endpoint individual: la foto debe existir en el disco de evidencia.
                $validator->after(fn (LaravelValidator $v) => ProofOfDeliveryRequest::verifyPhotoEvidence($v, $this->evidenceStorage));
            }
            /** @var array<string, mixed> $validated */
            $validated = $validator->validate();
            $shipmentId = is_string($validated['shipment_id'] ?? null) ? $validated['shipment_id'] : null;

            if ($kind === 'proof_of_delivery' && $shipmentId !== null && !StoreNodeScope::allows($request->user(), $shipmentId)) {
                return $this->rejected($index, $item, $key, $shipmentId, ProblemDetails::fromCode(ErrorCode::Forbidden, StoreNodeScope::DENIED_DETAIL), $request);
            }

            $this->commandBus->dispatch($this->commandFor($kind, $validated, $key, $request));
        } catch (ValidationException $e) {
            $problem = ProblemDetails::fromCode(ErrorCode::ValidationFailed, $e->getMessage())
                ->withErrors(array_map(static fn (array $messages): array => array_values($messages), $e->errors()));

            return $this->rejected($index, $item, $key, $shipmentId, $problem, $request);
        } catch (DomainException $e) {
            $problem = ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, $e->getMessage());

            return $this->rejected($index, $item, $key, $shipmentId, $problem, $request);
        } catch (Throwable $e) {
            // Falla inesperada (no de negocio): se reporta y NO se registra, así el reintento vuelve a intentar.
            report($e);

            return $this->outcome($index, $item, 'rejected', $shipmentId, ProblemDetails::fromCode(ErrorCode::ServerError, 'Unexpected error processing this item; retry later.'));
        }

        $this->ledger->remember($key, $kind, $shipmentId, 'accepted', null, $this->userId($request) ?: null);

        return $this->outcome($index, $item, 'accepted', $shipmentId);
    }

    /**
     * Reglas del ítem: las mismas del endpoint individual de su tipo.
     *
     * @return array<string, mixed>
     */
    private function rulesFor(string $kind, Request $request): array
    {
        $shipment = ['shipment_id' => ['required', 'uuid']];

        return match ($kind) {
            'preparation' => (new PreparationScanRequest)->rules(),
            'load' => (new LoadScanRequest)->rules(),
            'unload' => (new UnloadScanRequest)->rules(),
            'failed' => (new FailedDeliveryRequest)->rules(),
            'arrival' => array_merge((new StopArrivalRequest)->rules(), $shipment),
            'departure' => array_merge($shipment, ['occurred_at' => ['required', 'date']]),
            default => $this->proofOfDeliveryRules($request),
        };
    }

    /**
     * Reglas del POD con el usuario autenticado (el transportista no necesita `confirmation`).
     *
     * @return array<string, mixed>
     */
    private function proofOfDeliveryRules(Request $request): array
    {
        $pod = new ProofOfDeliveryRequest;
        $pod->setUserResolver(static fn () => $request->user());

        return $pod->rules();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function commandFor(string $kind, array $data, string $key, Request $request): Command
    {
        $device = $this->deviceId($request);
        $user = $this->userId($request);

        // Los ítems de escaneo cargan el `unit_id`/`unit_ids[]` tal como la app los leyó de la ETIQUETA
        // (label_id). Se normalizan a `shipment_unit.id` canónico igual que en los endpoints individuales;
        // los tipos sin bulto (departure/arrival) no se tocan.
        if (in_array($kind, ['preparation', 'load', 'unload', 'failed', 'proof_of_delivery'], true)) {
            $data = $this->resolveScannedUnitIds($data);
        }

        return match ($kind) {
            'preparation' => ScanCommandFactory::preparation($data, $device, $user, $key),
            'load' => ScanCommandFactory::load($data, $device, $user, $key),
            'unload' => ScanCommandFactory::unload($data, $device, $user, $key),
            'failed' => ScanCommandFactory::failed($data, $device, $user, $key),
            'departure' => ScanCommandFactory::departure($data),
            'arrival' => ScanCommandFactory::arrival($data, (string) $data['shipment_id']),
            default => ScanCommandFactory::proofOfDelivery($data, $request->user(), $device, $user, $key),
        };
    }

    /**
     * @param  array{idempotency_key: string, kind: string, occurred_at: string, payload?: array<string, mixed>|null} $item
     * @return array<string, mixed>
     */
    private function rejected(int $index, array $item, string $key, ?string $shipmentId, ProblemDetails $problem, Request $request): array
    {
        $this->ledger->remember($key, $item['kind'], $shipmentId, 'rejected', $problem->code->value, $this->userId($request) ?: null);

        return $this->outcome($index, $item, 'rejected', $shipmentId, $problem);
    }

    /**
     * @param  array{idempotency_key: string, kind: string, occurred_at: string, payload?: array<string, mixed>|null} $item
     * @return array<string, mixed>
     */
    private function outcome(int $index, array $item, string $result, ?string $shipmentId = null, ?ProblemDetails $problem = null): array
    {
        $payload = $item['payload'] ?? [];
        $payloadShipment = $payload['shipment_id'] ?? null;

        return [
            'index' => $index,
            'idempotency_key' => $item['idempotency_key'],
            'kind' => $item['kind'],
            'shipment_id' => $shipmentId ?? (is_string($payloadShipment) ? $payloadShipment : null),
            'result' => $result,
            'problem' => $problem?->toArray(),
        ];
    }
}
