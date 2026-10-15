<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\Response;

/**
 * Sirve el service worker de la página pública de rastreo (K-04): el script que recibe los eventos
 * `push` del navegador y muestra la notificación del envío.
 *
 * Se sirve desde /t/sw.js para que su scope por defecto sea /t/ (controla las páginas de rastreo). Es
 * JavaScript estático, sin datos del servidor, así que se puede cachear brevemente. No contiene PII ni
 * secretos: la clave pública VAPID la usa la página, no el worker.
 */
final class TrackingServiceWorkerController
{
    public function __invoke(): Response
    {
        return new Response($this->script(), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // Permite registrar el worker con scope de la raíz aunque se sirva desde /t/.
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Cuerpo del service worker. Muestra la notificación del push y, al tocarla, enfoca/abre la página
     * de rastreo. Tolerante: si el push no trae datos, usa un texto por defecto.
     */
    private function script(): string
    {
        return <<<'JS'
// Service worker del rastreo público de Dismac (Web Push). No guarda datos; solo muestra el aviso.
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var title = 'Dismac';
    var body = 'Hay novedades de tu pedido.';
    var url = '/';

    if (event.data) {
        try {
            var payload = event.data.json();
            title = payload.title || title;
            body = payload.body || payload.message || body;
            if (payload.url) { url = payload.url; }
        } catch (e) {
            body = event.data.text() || body;
        }
    }

    event.waitUntil(
        self.registration.showNotification(title, {
            body: body,
            icon: '/favicon.ico',
            badge: '/favicon.ico',
            data: { url: url },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if ('focus' in list[i]) { return list[i].focus(); }
            }
            if (self.clients.openWindow) { return self.clients.openWindow(target); }
        })
    );
});
JS;
    }
}
