---
name: bounded-context
description: >-
  Construir o extender un bounded context de la plataforma logística Dismac con la arquitectura
  objetivo del proyecto: DDD + CQRS + Clean Architecture, UUID v7 y outbox, orientada a microservicios
  (cada contexto extraíble a servicio) para procesamiento veloz de millones de registros y procesos
  transaccionales en simultáneo. Usar al crear un package nuevo en packages/logistics/<context> o al
  darle dominio a uno existente (network, coverage, fleet, shipments, planning, execution, tracking,
  notifications, capacity, promise, sap-connector, carriers).
---

# Bounded context — DDD + CQRS + Clean Architecture (Dismac logistics)

Arquitectura objetivo (memoria del proyecto, ver `CLAUDE.md` y `docs/adr/008-ddd-cqrs-uuidv7-outbox.md`):
respuestas rápidas sobre millones de registros, procesos transaccionales simultáneos, errores aislados
por capa, y cada contexto **extraíble a microservicio** sin reescribir.

## Reglas duras (no negociar)

1. **Clean Architecture**: dependencias `Presentation → Application → Domain ← Infrastructure`.
   - `Domain` es PHP puro: PROHIBIDO `use Illuminate\...`, Eloquent o helpers globales. Verificable:
     `grep -rl Illuminate packages/logistics/<ctx>/src/Domain` debe salir vacío.
   - `Presentation` nunca llama a `Infrastructure` ni a `Domain` directo: habla con los buses CQRS.
2. **DDD**: un agregado por raíz (`extends Logistics\Shared\Domain\Aggregate\AggregateRoot`), value
   objects `final readonly` con constructor privado + named constructors, eventos de dominio que
   extienden `Logistics\Shared\Domain\Event\BaseDomainEvent`, repositorios como interfaces en
   `Domain/Contract`.
3. **UUID v7**: todo id es un id tipado que extiende `Logistics\Shared\Domain\ValueObject\AbstractId`.
   Nunca enteros autoincrementales como identidad de dominio.
4. **CQRS**: escritura = `Command` + un `CommandHandler` (void); lectura = `Query` + `QueryHandler`
   que devuelve ReadModels (`final readonly`, `toArray()` primitivo). Registrar cada par
   mensaje→handler en el `HandlerRegistry` desde el `boot()` del ServiceProvider.
5. **Outbox para I/O externo**: los handlers de escritura hacen `releaseEvents()` del agregado y los
   mandan al `logistics/outbox` (`RecordDomainEvents`) en la MISMA transacción. Nada de HTTP síncrono.
   El despacho real (SAP/FCM/email/SMS) es un `MessageDispatcher` que corre en el relay, idempotente.
6. **Cache**: si se cachea, se cachea la forma primitiva (array del ReadModel), nunca el objeto de dominio.
7. Texto visible de admin/consola **en inglés**; comentarios y docs **en español**.

## Estructura de carpetas

```
packages/logistics/<context>/
├── composer.json            # name logistics/<context>; PSR-4 "Logistics\<Context>\": "src/";
│                            #   require logistics/shared ^0.1; provider en extra.laravel.providers
├── README.md                # responsabilidad, no-responsabilidad, estado
├── database/migrations/     # uuid pk string; jsonb; PostGIS por SQL donde haya geometría
├── src/
│   ├── Domain/              # ValueObject/, Model/ (agregados+entidades), Event/, Contract/, Service/, Exception/
│   ├── Application/         # Command/<UseCase>/{Command,Handler}, Query/<Q>/{Query,Handler}, ReadModel/
│   ├── Infrastructure/      # Persistence/Eloquent/{Record,Mapper,Repository}, Providers/<Ctx>ServiceProvider
│   └── Presentation/        # Console/, Http/ (controllers), Filament/
└── tests/{Unit,Feature}/
```

## Núcleo compartido disponible (`logistics/shared`)

- `Domain\ValueObject\Uuid` (v7: `generate()`, `fromString()`, `->value`, `->equals()`, `timestampMillis()`).
- `Domain\ValueObject\AbstractId` (base de ids tipados: `generate()/fromString()/fromUuid()/toString()/equals()`).
- `Domain\Aggregate\AggregateRoot` (`recordEvent()` protected, `releaseEvents(): list<DomainEvent>`).
- `Domain\Event\{DomainEvent,BaseDomainEvent}` (ctor `(string $aggregateId, ?Uuid, ?DateTimeImmutable)`;
  definís `eventName()` namespaced `"<ctx>.<hecho>"` y `payload(): array<string,mixed>`).
- `Domain\Clock\Clock`, `Domain\Exception\DomainException`.
- `Application\Command\{Command,CommandHandler,CommandBus}`, `Application\Query\{Query,QueryHandler,QueryBus}`,
  `Application\HandlerRegistry` (`register(string $msgClass, string $handlerClass)`).

## Pasos

1. Crear la estructura y el `composer.json` (copiar `packages/logistics/labels/composer.json` como molde).
2. Modelar el `Domain` (ids tipados, VOs, agregado con named constructors que graban eventos, contratos de repo).
3. Escribir `Application`: un command+handler por caso de uso de escritura; queries+handlers con ReadModels.
4. `Infrastructure`: modelo Eloquent (`$incrementing=false`, `$keyType='string'`), mapper dominio↔fila,
   repo Eloquent, migración (uuid pk; PostGIS con `ST_*` por SQL si hay geometría), y el ServiceProvider
   que bindea repos, registra handlers en `HandlerRegistry` y hace `loadMigrationsFrom`.
5. **Registrar el package en DOS lugares** (regla 3 de `CLAUDE.md`): `bootstrap/providers.php` y
   `extra.laravel.dont-discover` del `composer.json` raíz. El núcleo `SharedServiceProvider` va primero.
6. Tests: `Unit` (dominio, sin Laravel) + `Feature` (stack contra PostgreSQL real; PostGIS si aplica).

## Definición de Terminado (igual que `CLAUDE.md`)

`docker compose exec -T app ./vendor/bin/pest` verde (reportar nº de tests y aserciones) +
`phpstan analyse` nivel 8 limpio + `./vendor/bin/pint` limpio + verificación real contra containers +
`openapi/v1.yaml` actualizado si se agregó endpoint. Sin Docker disponible, dejar `php -l` limpio y
marcar explícitamente que la verificación en containers queda pendiente (no es "terminado").

## Referencia

`packages/logistics/network`, `fleet`, `coverage`, `shipments` ya siguen este patrón; `labels` para el
estilo de VOs y comandos; `outbox` y `settings` para persistencia e idempotencia.
