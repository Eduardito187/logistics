# logistics/execution — Ejecución

## Responsabilidad

Preparación (escaneo de EAN → etiqueta → carril), carga validada, ruta del chofer, descarga guiada, prueba de entrega (QR del cliente, foto), entregas fallidas, recepción en nodos. Registra cada escaneo como evento inmutable.

## No es responsable de

Notificar al cliente: publica eventos que consume tracking/notifications.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
