# logistics/carriers — Transportistas terceros

## Responsabilidad

Transportistas terceros (identidad, NIT, estado, documentos), tarifas por corredor y clase de vehículo,
y calificación/desempeño. Da el insumo de costo para la **consolidación entre departamentos**: por el
volumen de un corredor se decide moverlo en tráiler, camión o transportista tercero, y este contexto
conoce la tarifa de cada opción y el desempeño de cada transportista.

## No es responsable de

Decidir qué envío va por qué corredor ni por qué clase de vehículo (eso es `planning`), ni de ejecutar
la asignación. Tampoco conoce la red de nodos: referencia a los corredores por texto, con el mismo
vocabulario que `network` (CD→CD, CD→tienda, etc.) pero **sin depender** del package `network`.

## Estructura

Cuatro capas, igual que los demás packages (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries) sobre `logistics/shared` (CQRS).
- `src/Infrastructure`: Eloquent, mappers, repositorios, provider.
- `src/Presentation`: controllers, Filament, consola (aún sin implementar).

## Estado

Dominio y casos de uso base implementados (DDD + CQRS sobre `logistics/shared`). Fase 2.

### Dominio

- IDs tipados: `CarrierId`, `TariffId` (UUID v7).
- Value objects: `Money` (centavos enteros + moneda, operaciones seguras), `CorridorRef`
  (origen→destino como texto + tipo validado contra el vocabulario de network), `Rating` (1..5).
- Enums: `CarrierStatus` (active / suspended), `VehicleClass` (trailer / truck / third_party).
- Agregados: `Carrier` (registro, suspensión/reactivación, promedio de calificación incremental),
  `Tariff` (precio por corredor y clase, con vigencia).
- Eventos: `CarrierRegistered`, `CarrierRated`, `CarrierSuspended`, `CarrierReactivated`, `TariffSet`.
- Contratos de repositorio: `CarrierRepository`, `TariffRepository` (incluye `findTariff` y
  `cheapestForCorridor`).

### Aplicación (CQRS)

- Commands: `RegisterCarrier`, `SuspendCarrier`, `SetTariff`, `RateCarrier`.
- Queries: `ListCarriers`, `GetTariffForCorridor`, `CheapestCarrierForCorridor`.
- Read models: `CarrierView`, `TariffView`.

### Infraestructura

- Modelos Eloquent, mappers y repositorios para las tablas `carriers` y `carrier_tariffs`
  (PK UUID en texto, no autoincremental).
- `CarriersServiceProvider` bindea los repositorios y registra los handlers en el `HandlerRegistry`.

### Pendiente

- Publicar los eventos liberados vía outbox al integrar el package correspondiente (hoy se liberan y
  se descartan; ver los `TODO(outbox)` en los handlers).
- Presentación (HTTP / Filament / consola) y pruebas Feature contra PostgreSQL real.
- Registrar el provider en `bootstrap/providers.php` y en `extra.laravel.dont-discover` del
  `composer.json` raíz (regla 3 de CLAUDE.md) — fuera del alcance de este cambio por indicación.
