<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Traduce cualquier excepción a una respuesta RFC 7807 uniforme para las tres puertas
 * de la API (`/api/v1/app/*`, `/api/v1/channels/*`, `/api/v1/integrations/*`).
 *
 * Solo actúa sobre peticiones `api/*`: así el panel admin (web/Livewire) conserva su
 * propio manejo de errores (incluida la redirección de deep-links en bootstrap/app.php).
 */
final class ProblemExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (!$request->is('api/*')) {
            return null;
        }

        $problem = $this->toProblem($e);

        $response = $problem->toResponse($request);

        // Propaga cabeceras significativas de las excepciones HTTP (Allow, Retry-After,
        // X-RateLimit-*…). Los valores se normalizan a string: el throttle las entrega como enteros
        // (p. ej. Retry-After), y ResponseHeaderBag::set() solo admite array|string|null, así que un
        // int crudo convertía cualquier 429 en un 500.
        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set(
                    $name,
                    is_array($value) ? array_map(static fn ($v): string => (string) $v, $value) : (string) $value,
                    false,
                );
            }
        }

        return $response;
    }

    private function toProblem(Throwable $e): ProblemDetails
    {
        return match (true) {
            $e instanceof ValidationException => ProblemDetails::fromCode(ErrorCode::ValidationFailed, $e->getMessage())
                ->withErrors($this->normalizeErrors($e->errors())),

            $e instanceof AuthenticationException => ProblemDetails::fromCode(
                ErrorCode::Unauthenticated,
                'Authentication is required to access this resource.',
            ),

            $e instanceof AuthorizationException => ProblemDetails::fromCode(
                ErrorCode::Forbidden,
                $this->meaningfulMessage($e) ?? 'This action is not allowed.',
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ProblemDetails::fromCode(
                ErrorCode::NotFound,
                'The requested resource does not exist.',
            ),

            $e instanceof MethodNotAllowedHttpException => ProblemDetails::fromCode(
                ErrorCode::MethodNotAllowed,
                $this->meaningfulMessage($e),
            ),

            $e instanceof TooManyRequestsHttpException => ProblemDetails::fromCode(
                ErrorCode::RateLimited,
                'Request rate limit exceeded; retry later.',
            ),

            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),

            default => $this->fromUnexpected($e),
        };
    }

    private function fromHttpException(HttpExceptionInterface $e): ProblemDetails
    {
        $code = ErrorCode::fromStatus($e->getStatusCode());

        return ProblemDetails::fromCode($code, $this->meaningfulMessage($e));
    }

    private function fromUnexpected(Throwable $e): ProblemDetails
    {
        // Nunca se filtra el mensaje interno salvo en modo debug (evita fugar detalles
        // de infraestructura a los clientes de la API).
        $debug = (bool) (function_exists('config') ? config('app.debug', false) : false);

        return ProblemDetails::fromCode(
            ErrorCode::ServerError,
            $debug ? $e->getMessage() : 'An unexpected error occurred.',
        );
    }

    /**
     * Devuelve el mensaje de la excepción solo si aporta algo (algunas excepciones HTTP
     * traen el mensaje vacío, en cuyo caso se usa el título del código).
     */
    private function meaningfulMessage(Throwable $e): ?string
    {
        $message = trim($e->getMessage());

        return $message === '' ? null : $message;
    }

    /**
     * Normaliza el mapa de errores de validación a `array<string, list<string>>`.
     *
     * @param  array<string, array<int, string>> $errors
     * @return array<string, list<string>>
     */
    private function normalizeErrors(array $errors): array
    {
        $normalized = [];

        foreach ($errors as $field => $messages) {
            $normalized[$field] = array_values($messages);
        }

        return $normalized;
    }
}
