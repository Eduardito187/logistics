# logistics/notifications — Notificaciones al cliente

## Responsabilidad

Capa de notificaciones con cascada de canales y reglas configurables: push de la app de clientes (topics FCM por envío), web push en la página de tracking, email y SMS solo como respaldo crítico. Sin WhatsApp (ADR 006).

## No es responsable de

Decidir el contenido del tracking.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
