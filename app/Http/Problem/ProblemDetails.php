<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documento de error RFC 7807 (`application/problem+json`).
 *
 * Es inmutable: los métodos `with*` devuelven una copia. Centraliza la forma del cuerpo
 * de error de las tres puertas de la API para que todos los clientes reciban SIEMPRE la
 * misma estructura:
 *
 *   {
 *     "type":     "<base>/<código>",   // URI de referencia del tipo de problema
 *     "title":    "...",               // resumen estable legible por humanos
 *     "status":   422,                 // mismo valor que el estado HTTP
 *     "detail":   "...",               // explicación de esta ocurrencia (opcional)
 *     "instance": "/api/v1/...",       // la petición que falló (opcional)
 *     "code":     "validation_failed", // código del catálogo (legible por máquina)
 *     "errors":   { "campo": ["..."] } // errores por campo (solo validación, opcional)
 *   }
 */
final class ProblemDetails
{
    /**
     * @param array<string, list<string>>             $errors
     * @param array<string, scalar|array<mixed>|null> $extensions
     */
    public function __construct(
        public readonly ErrorCode $code,
        public readonly int $status,
        public readonly string $title,
        public readonly string $type,
        public readonly ?string $detail = null,
        public readonly ?string $instance = null,
        public readonly array $errors = [],
        public readonly array $extensions = [],
    ) {}

    /**
     * Construye un problema a partir de un código del catálogo, tomando estado y título
     * por defecto. La base de la URI `type` se lee de config('api.problem_type_base_uri')
     * salvo que se pase explícitamente (útil en tests puros sin Laravel).
     */
    public static function fromCode(ErrorCode $code, ?string $detail = null, ?string $typeBaseUri = null): self
    {
        $base = $typeBaseUri ?? self::configuredTypeBaseUri();

        return new self(
            code: $code,
            status: $code->status(),
            title: $code->title(),
            type: rtrim($base, '/').'/'.$code->value,
            detail: $detail,
        );
    }

    public function withDetail(?string $detail): self
    {
        return new self($this->code, $this->status, $this->title, $this->type, $detail, $this->instance, $this->errors, $this->extensions);
    }

    public function withInstance(?string $instance): self
    {
        return new self($this->code, $this->status, $this->title, $this->type, $this->detail, $instance, $this->errors, $this->extensions);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($this->code, $this->status, $this->title, $this->type, $this->detail, $this->instance, $errors, $this->extensions);
    }

    /**
     * Añade un miembro de extensión arbitrario al problema (p. ej. `retry_after`).
     *
     * @param scalar|array<mixed>|null $value
     */
    public function withExtension(string $name, mixed $value): self
    {
        $extensions = $this->extensions;
        $extensions[$name] = $value;

        return new self($this->code, $this->status, $this->title, $this->type, $this->detail, $this->instance, $this->errors, $extensions);
    }

    /**
     * Cuerpo del problema listo para serializar a JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'code' => $this->code->value,
        ];

        if ($this->detail !== null && $this->detail !== '') {
            $body['detail'] = $this->detail;
        }

        if ($this->instance !== null && $this->instance !== '') {
            $body['instance'] = $this->instance;
        }

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        foreach ($this->extensions as $name => $value) {
            // Las extensiones no pisan los miembros estándar ya definidos.
            if (!array_key_exists($name, $body)) {
                $body[$name] = $value;
            }
        }

        return $body;
    }

    /**
     * Respuesta HTTP con el content-type correcto (`application/problem+json`). El
     * miembro `instance` se completa con la ruta de la petición si no venía fijado.
     */
    public function toResponse(?Request $request = null): JsonResponse
    {
        $problem = $this;

        if ($this->instance === null && $request !== null) {
            $problem = $this->withInstance('/'.ltrim($request->getPathInfo(), '/'));
        }

        return new JsonResponse(
            $problem->toArray(),
            $problem->status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    private static function configuredTypeBaseUri(): string
    {
        $base = function_exists('config') ? config('api.problem_type_base_uri', '/problems') : '/problems';

        return is_string($base) ? $base : '/problems';
    }
}
