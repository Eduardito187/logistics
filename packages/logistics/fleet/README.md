# logistics/fleet — Flota y tripulación

## Responsabilidad

Vehículos (capacidad kg/m³, CD base, estado, documentos con vencimiento), choferes (licencia, vencimiento, vehículos habilitados), ayudantes, turnos y descansos. Asignación de tripulación y checklist pre-salida.

## No es responsable de

Planificar qué pedidos lleva cada camión.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
