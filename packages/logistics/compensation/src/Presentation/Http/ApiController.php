<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Base de los controllers HTTP del contexto Compensación (puerta `integrations`).
 *
 * Centraliza el envelope de éxito (`data`) y la traducción de las excepciones de dominio a errores
 * RFC 7807 (`App\Http\Problem\ProblemDetails`), con los mismos códigos que el resto de la API. La
 * Presentation solo habla con los buses CQRS; nunca toca Infrastructure ni Domain, salvo capturar su
 * excepción base para mapearla al código HTTP correcto.
 *
 * El mapeo del vale se hace por el mensaje estable de la `CompensationException` (constantes del propio
 * package, en español): no se revela si un código existe (fraude) — un código inexistente, mal formado o
 * con dígito de control inválido devuelve SIEMPRE un 404 genérico.
 */
abstract class ApiController
{
    /**
     * Clasificación de los mensajes de dominio del vale: subcadena estable → [estado, razón, detalle EN].
     *
     * @var array<string, array{int, string, string}>
     */
    private const VOUCHER_ERRORS = [
        'no encontrado' => [404, 'voucher_not_found', 'Voucher not found.'],
        'Código de vale inválido' => [404, 'voucher_not_found', 'Voucher not found.'],
        'Dígito de control' => [404, 'voucher_not_found', 'Voucher not found.'],
        'vencido' => [410, 'voucher_expired', 'The voucher has expired.'],
        'ya fue canjeado' => [409, 'voucher_redeemed', 'The voucher has already been redeemed.'],
        'ya está reservado' => [409, 'voucher_held', 'The voucher is currently held by another sale.'],
        'pertenece a otro cliente' => [409, 'voucher_holder_mismatch', 'The voucher belongs to another customer.'],
        'anulado' => [409, 'voucher_revoked', 'The voucher has been revoked.'],
    ];

    /**
     * Respuesta de éxito con cuerpo de datos bajo la clave `data`.
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    protected function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Acuse de comando aceptado (escritura asíncrona vía outbox): no devuelve recurso, solo el acuse.
     *
     * @param array<string, mixed> $echo
     */
    protected function accepted(array $echo = []): JsonResponse
    {
        return new JsonResponse(['data' => ['accepted' => true] + $echo], 202);
    }

    /**
     * Traduce una excepción de dominio del VALE a su código HTTP (404/409/410/422) en RFC 7807.
     */
    protected function voucherProblem(CompensationException $e, Request $request): JsonResponse
    {
        $message = $e->getMessage();
        foreach (self::VOUCHER_ERRORS as $needle => [$status, $reason, $detail]) {
            if (str_contains($message, $needle)) {
                return $this->voucherError($status, $reason, $detail, $request);
            }
        }

        // Regla de negocio no clasificada (p. ej. monto canjeado > monto del vale): 422.
        return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, $message)
            ->withExtension('reason', 'business_rule_violation')
            ->toResponse($request);
    }

    /**
     * Traduce una excepción de dominio del CASO (apertura/consulta) a 422 regla de negocio.
     */
    protected function caseProblem(CompensationException $e, Request $request): JsonResponse
    {
        return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, $e->getMessage())
            ->withExtension('reason', 'business_rule_violation')
            ->toResponse($request);
    }

    /**
     * Error RFC 7807 con el estado y la razón de máquina dados. El 410 no tiene un `ErrorCode` propio en
     * el catálogo compartido, así que se construye a mano conservando la razón estable como extensión.
     */
    private function voucherError(int $status, string $reason, string $detail, Request $request): JsonResponse
    {
        $code = match ($status) {
            404 => ErrorCode::NotFound,
            409 => ErrorCode::Conflict,
            default => ErrorCode::BusinessRuleViolation,
        };

        if ($status === 410) {
            return $this->gone($reason, $detail, $request);
        }

        return ProblemDetails::fromCode($code, $detail)
            ->withExtension('reason', $reason)
            ->toResponse($request);
    }

    /**
     * 410 Gone (vale vencido): recurso que existió pero ya no es canjeable.
     */
    private function gone(string $reason, string $detail, Request $request): JsonResponse
    {
        $base = config('api.problem_type_base_uri', '/problems');
        $base = is_string($base) ? $base : '/problems';

        return (new ProblemDetails(
            code: ErrorCode::BusinessRuleViolation,
            status: 410,
            title: 'Voucher expired',
            type: rtrim($base, '/').'/'.$reason,
            detail: $detail,
        ))->withExtension('reason', $reason)->toResponse($request);
    }
}
