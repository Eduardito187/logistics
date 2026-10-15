# logistics/promise — Promesa de entrega para canales

## Responsabilidad

Calcular las fechas y franjas disponibles para prometerle una entrega a un canal (app de clientes, web,
call center) y gobernar la reserva temporal de ese cupo: al prometer se reserva un cupo por una ventana
de tiempo; luego la promesa se confirma (si la ventana no venció) o la reserva expira y se libera.

Ciclo de vida de una promesa: `Quoted → Reserved → Confirmed` (camino feliz) o `Reserved → Expired`
(la ventana venció antes de confirmar).

## No es responsable de

- Calcular el cupo real ni la cobertura: eso lo deciden `logistics/capacity` y `logistics/coverage`.
  Este contexto los consulta SOLO por contrato (`AvailabilityProvider`, `SlotReserver`) con primitivos;
  no importa esos packages.
- Planificar la ruta ni asignar camión: eso es `logistics/planning` / `logistics/execution`.

## Estructura

Cuatro capas, igual que el resto de los contextos (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel. Agregado `DeliveryPromise`, VOs, eventos,
  contratos (puertos hacia capacity/coverage y hacia la persistencia).
- `src/Application`: casos de uso CQRS (commands y queries) con sus ReadModels.
- `src/Infrastructure`: Eloquent, stubs deterministas de los puertos, provider.
- `src/Presentation`: controllers HTTP (puerta channels) y Filament Resource (solo lectura).
- `database/factories` y `database/seeders`: factory de `PromiseRecord` y `PromiseSeeder` con datos de demo.

## Puertos hacia otros contextos

- `AvailabilityProvider`: dado `zoneId` + fecha devuelve las franjas con cupo disponible. En producción
  consultará `capacity` y `coverage`; aquí se entrega el stub determinista `StubAvailabilityProvider`.
- `SlotReserver`: reserva y libera cupo en una franja. Stub determinista `StubSlotReserver`.

Ambos stubs están pensados para reemplazarse por la integración real sin tocar el dominio.

## Estado

Dominio, CQRS, persistencia Eloquent + migración, controllers de la puerta channels, Filament Resource,
factory y seeder de demo implementados. Pendiente: integración real con `capacity`/`coverage` (hoy tras
los stubs `StubAvailabilityProvider`/`StubSlotReserver`) y verificación en containers. Fase 2 ("Prometer").
