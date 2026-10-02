# logistics/network — Red logística: nodos y tramos

## Responsabilidad

Nodos (CD, tiendas, puntos de recojo; más adelante lockers o agentes) con sus capacidades. Un envío es una secuencia de tramos entre nodos: CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD, recojo en tienda.

## No es responsable de

Decidir rutas o camiones: eso es planificación.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
