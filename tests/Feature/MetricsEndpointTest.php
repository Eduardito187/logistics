<?php

declare(strict_types=1);

// Endpoint de métricas Prometheus (/api/metrics, auditoría M-6): protegido por token/IP y en formato
// de texto Prometheus. Verifica el gate (fail-closed) y el contenido de las señales RED/USE.

beforeEach(function (): void {
    // Estado por defecto: sin token ni allowlist (fail-closed).
    config()->set('observability.metrics.token', null);
    config()->set('observability.metrics.ip_allowlist', []);
});

it('denies access when no token or allowlist is configured (fail-closed)', function (): void {
    // Sin token ni allowlist el endpoint no se expone: responde 404.
    $this->get('/api/metrics')->assertNotFound();
});

it('rejects requests without the correct token', function (): void {
    config()->set('observability.metrics.token', 'super-secret');

    $this->get('/api/metrics')->assertNotFound();
    $this->get('/api/metrics', ['X-Metrics-Token' => 'wrong'])->assertNotFound();
    $this->get('/api/metrics', ['Authorization' => 'Bearer wrong'])->assertNotFound();
});

it('serves Prometheus metrics with a valid bearer token', function (): void {
    config()->set('observability.metrics.token', 'super-secret');

    $response = $this->get('/api/metrics', ['Authorization' => 'Bearer super-secret']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($response->headers->get('Content-Type'))->toContain('version=0.0.4')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $body = $response->getContent();

    // Formato Prometheus: cabeceras HELP/TYPE y las series esperadas.
    expect($body)->toContain('# HELP logistics_up')
        ->and($body)->toContain('# TYPE logistics_up gauge')
        ->and($body)->toContain('logistics_up 1')
        ->and($body)->toContain('logistics_queue_depth{queue="outbox"}')
        ->and($body)->toContain('logistics_outbox_messages{status="pending"}')
        ->and($body)->toContain('logistics_outbox_messages{status="failed"}')
        ->and($body)->toContain('logistics_outbox_messages{status="dead_lettered"}')
        ->and($body)->toContain('logistics_failed_jobs_total');
});

it('accepts the X-Metrics-Token header as an alternative to the bearer token', function (): void {
    config()->set('observability.metrics.token', 'super-secret');

    $this->get('/api/metrics', ['X-Metrics-Token' => 'super-secret'])->assertOk();
});

it('grants access by IP allowlist without a token', function (): void {
    config()->set('observability.metrics.ip_allowlist', ['127.0.0.1']);

    // El cliente de pruebas usa 127.0.0.1 como IP remota.
    $this->get('/api/metrics')->assertOk();
});
