<?php

declare(strict_types=1);

use App\Support\Http\StaticErrorPages;

/**
 * Candado de las páginas de error del servidor web: nginx nunca debe mostrar su página cruda (delata el
 * software y rompe la marca). Vigila que los archivos estáticos versionados estén al día con las vistas,
 * que no filtren datos del servidor y que la configuración de nginx cubra cada error que puede responder
 * por su cuenta.
 */
function nginxFile(string $relative): string
{
    return (string) file_get_contents(base_path('.docker/nginx/'.$relative));
}

it('keeps the static error pages in sync with the error views', function (): void {
    $outdated = app(StaticErrorPages::class)->outdated(base_path(StaticErrorPages::DIRECTORY));

    expect($outdated)->toBe([], 'Correr: php artisan logistics:error-pages:build');
});

it('renders branded pages without server details', function (): void {
    foreach (app(StaticErrorPages::class)->render() as $name => $content) {
        expect($content)->not->toContain('nginx')
            ->and($content)->not->toContain('PHP')
            ->and($content)->not->toContain('Laravel');

        if (str_ends_with($name, '.html')) {
            expect($content)->toContain('DisTrack')
                ->and($content)->toContain('ERR-'.substr($name, 0, 3));
        }
    }
});

it('serves API errors with the unified problem contract', function (): void {
    $files = app(StaticErrorPages::class)->render();

    foreach ([400, 403, 404, 405, 408, 413, 429, 500, 502, 503, 504] as $status) {
        /** @var array<string, mixed> $body */
        $body = json_decode($files["{$status}.json"], true, 512, JSON_THROW_ON_ERROR);

        expect($body['status'])->toBe($status)
            ->and($body['detail'])->toBeString()->not->toBe('')
            ->and($body['error']['message'])->toBe($body['detail'])
            ->and($body['error']['code'])->toBe($body['code'])
            ->and($body['type'])->toBe('/problems/'.$body['code']);
    }
});

it('covers every error nginx can answer on its own and hides the server version', function (): void {
    $snippet = nginxFile('snippets/error-pages.conf');

    expect($snippet)->toContain('server_tokens off;')
        ->and($snippet)->toContain('recursive_error_pages on;');

    foreach ([400, 403, 405, 408, 413, 414, 416, 429, 431, 494, 495, 496, 497, 500, 501, 502, 503, 504, 505, 507] as $status) {
        expect(preg_match('/^\s*error_page[^;]*\b'.$status.'\b[^;]*;/m', $snippet))
            ->toBe(1, "Falta error_page para {$status}");
    }

    // Cada bloque `server` incluye el snippet; los que redefinen error_page en una location lo repiten ahí
    // (un error_page propio anula los heredados en ese nivel).
    foreach (['default.conf', 'ha-upstream.conf'] as $conf) {
        $body = nginxFile($conf);
        expect($body)->toContain('include /etc/nginx/snippets/error-pages.conf;')
            ->and($body)->toContain('map $request_uri $error_page_format');
    }
});

it('ships the error pages to every nginx that serves the platform', function (): void {
    expect((string) file_get_contents(base_path('docker-compose.yml')))
        ->toContain('./.docker/nginx/errors:/etc/nginx/errors:ro')
        ->toContain('./.docker/nginx/snippets/error-pages.conf:/etc/nginx/snippets/error-pages.conf:ro');

    expect((string) file_get_contents(base_path('.docker/php/Dockerfile')))
        ->toContain('RUN php artisan logistics:error-pages:build')
        ->toContain('COPY --from=prod /var/www/html/.docker/nginx/errors /etc/nginx/errors')
        ->toContain('COPY .docker/nginx/snippets/error-pages.conf /etc/nginx/snippets/error-pages.conf');

    // Y se regeneran solas en cada arranque del contenedor de la app.
    expect((string) file_get_contents(base_path('.docker/php/entrypoint.sh')))
        ->toContain('php artisan logistics:error-pages:build');
});
