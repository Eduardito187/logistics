# logistics/tracking — Tracking

## Responsabilidad

Estado visible de cada envío y bulto, paradas restantes, ETA, página pública por token y posición en vivo del camión.

## No es responsable de

Elegir canal de notificación.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
