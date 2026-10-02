# logistics/planning — Planificación

## Responsabilidad

Propuesta automática pedido→camión, secuencia, ETA, plan de carga (orden invertido y carril). Ajuste humano y publicación. Motor de rutas detrás de una interfaz propia (Google Route Optimization primero). Más adelante: consolidación por corredor entre departamentos.

## No es responsable de

Ejecutar la ruta o registrar escaneos.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
