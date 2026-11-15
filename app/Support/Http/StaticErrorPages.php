<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Contracts\View\Factory;

/**
 * Páginas de error ESTÁTICAS para el servidor web (nginx).
 *
 * Hay errores que la aplicación nunca llega a ver: los responde el servidor web solo (petición mal formada,
 * archivo demasiado grande, método no permitido) o los produce cuando la aplicación está caída o
 * reiniciando (502 / 503 / 504). Sin esto, el visitante recibe la página cruda del servidor, que delata el
 * software que hay detrás y rompe la imagen del producto.
 *
 * Esta clase hornea, a partir de las MISMAS vistas `errors.*` que usa Laravel, un archivo por código:
 *   - `<código>.html`: la página de marca, en modo estático (sin sesión, hora ni ruta).
 *   - `<código>.json`: el cuerpo RFC 7807 del contrato de la API, para las rutas `/api/*`.
 *
 * Los archivos viven versionados en `.docker/nginx/errors/` y nginx los sirve por `error_page` (ver
 * `.docker/nginx/snippets/error-pages.conf`). Se regeneran con `logistics:error-pages:build`; un test
 * vigila que no queden desactualizados respecto de las vistas.
 */
final readonly class StaticErrorPages
{
    /** Carpeta versionada (relativa a la raíz del proyecto) donde quedan los archivos. */
    public const string DIRECTORY = '.docker/nginx/errors';

    /**
     * La base de la URI `type` se fija acá (no se lee de la configuración) para que el resultado sea el
     * mismo en cualquier entorno: es el valor por defecto de `api.problem_type_base_uri`.
     */
    private const string PROBLEM_TYPE_BASE = '/problems';

    /**
     * Código HTTP → [código del catálogo, título, detalle] del cuerpo JSON. Cada código tiene además su
     * vista `errors.<código>`.
     *
     * @var array<int, array{ErrorCode, string, string}>
     */
    private const array PAGES = [
        400 => [ErrorCode::BadRequest, 'Bad request', 'The request could not be understood.'],
        403 => [ErrorCode::Forbidden, 'Forbidden', 'You do not have access to this resource.'],
        404 => [ErrorCode::NotFound, 'Resource not found', 'The requested resource does not exist.'],
        405 => [ErrorCode::MethodNotAllowed, 'Method not allowed', 'This method is not allowed for the requested resource.'],
        408 => [ErrorCode::BadRequest, 'Request timeout', 'The request took too long to arrive. Try again.'],
        413 => [ErrorCode::PayloadTooLarge, 'Payload too large', 'The request body exceeds the allowed size.'],
        429 => [ErrorCode::RateLimited, 'Too many requests', 'Too many requests. Try again in a moment.'],
        500 => [ErrorCode::ServerError, 'Internal server error', 'Something went wrong on our side. Try again in a few minutes.'],
        502 => [ErrorCode::ServiceUnavailable, 'Service unavailable', 'The service is temporarily unavailable. Try again in a few minutes.'],
        503 => [ErrorCode::ServiceUnavailable, 'Service unavailable', 'The service is temporarily unavailable. Try again in a few minutes.'],
        504 => [ErrorCode::ServiceUnavailable, 'Service unavailable', 'The service took too long to respond. Try again in a few minutes.'],
    ];

    public function __construct(
        private Factory $views,
    ) {}

    /**
     * Contenido de cada archivo, por nombre (`502.html`, `502.json`, …).
     *
     * @return array<string, string>
     */
    public function render(): array
    {
        $files = [];
        foreach (self::PAGES as $status => [$code, $title, $detail]) {
            $html = $this->views->make("errors.{$status}", ['static' => true])->render();
            $files["{$status}.html"] = rtrim(str_replace("\r\n", "\n", $html))."\n";

            $problem = new ProblemDetails(
                code: $code,
                status: $status,
                title: $title,
                type: self::PROBLEM_TYPE_BASE.'/'.$code->value,
                detail: $detail,
            );
            $files["{$status}.json"] = json_encode($problem->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        }

        return $files;
    }

    /**
     * Nombres de los archivos cuyo contenido en disco no coincide con lo que generan hoy las vistas (o que
     * faltan). Vacío = al día.
     *
     * @return list<string>
     */
    public function outdated(string $directory): array
    {
        $outdated = [];
        foreach ($this->render() as $name => $content) {
            $path = $directory.'/'.$name;
            $current = is_file($path) ? file_get_contents($path) : false;
            if ($current === false || str_replace("\r\n", "\n", $current) !== $content) {
                $outdated[] = $name;
            }
        }

        return $outdated;
    }

    /**
     * Escribe los archivos en la carpeta indicada y devuelve cuántos escribió.
     */
    public function write(string $directory): int
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $files = $this->render();
        foreach ($files as $name => $content) {
            file_put_contents($directory.'/'.$name, $content);
        }

        return count($files);
    }
}
