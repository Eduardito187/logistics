# Guía para agentes — logistics (backend)

Plataforma logística de Dismac. Antes de trabajar, leer `docs/vision.md` y `docs/architecture.md`.

## Cómo correr cosas

Todo corre en Docker; nunca usar el PHP del host.

- Tests: `docker compose exec -T app ./vendor/bin/pest` (o `make test`)
- Estilo: `docker compose exec -T app ./vendor/bin/pint` (o `make pint-fix`)
- Análisis: `docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G` (nivel 8)
- Artisan: `docker compose exec -T app php artisan <comando>`

## Arquitectura (resumen)

- Monolito modular: un package por bounded context en `packages/logistics/<context>`,
  namespace `Logistics\<Context>\`, cuatro capas: Domain / Application / Infrastructure / Presentation.
- Domain es PHP puro: sin Laravel, sin Eloquent, sin helpers globales.
- Presentation llama a Application; nunca a Infrastructure ni a Domain directo.
- La plataforma vive FUERA de SAP: SAP solo se toca desde el conector, vía outbox (ADR 002).
- Todo I/O externo (SAP, FCM, email, SMS, transportistas) pasa por outbox; nunca HTTP síncrono
  dentro de un request de usuario.
- Contrato de API en `openapi/v1.yaml`: se actualiza en el mismo cambio que el endpoint.

## Arquitectura objetivo (decisión del dueño del proyecto — memoria)

Meta explícita: respuestas rápidas y procesamiento veloz sobre **millones de registros**, con
**procesos transaccionales en simultáneo**. Se construye con **DDD + CQRS + Clean Architecture** para
aislar errores, y **orientado a microservicios**: cada bounded context es autónomo y **extraíble a un
servicio independiente** cuando el volumen lo pida (hoy vive como monolito modular, ADR 001/008).

Cómo se materializa (ver `docs/adr/008-ddd-cqrs-uuidv7-outbox.md` y `packages/logistics/shared/`):

- **Clean Architecture por capas** en cada contexto: `Presentation → Application → Domain ← Infrastructure`.
  El `Domain` no conoce Laravel, Eloquent ni helpers globales. Un error en un adaptador (SAP, FCM, DB)
  no contamina el dominio: queda aislado en su capa.
- **DDD táctico**: agregados (`AggregateRoot`), value objects inmutables (`final readonly`), eventos de
  dominio (`DomainEvent`) y repositorios como contratos. Un package por bounded context.
- **CQRS**: escritura por comandos (`Command` → un `CommandHandler`), lectura por consultas
  (`Query` → `QueryHandler`), despachadas por `CommandBus`/`QueryBus` (núcleo `logistics/shared`). Las
  lecturas usan ReadModels optimizados; así se escala y cachea cada lado por separado.
- **Identidad para escala**: todo id es **UUID v7** (ordenable en el tiempo, índices compactos, único
  sin coordinación entre contextos/nodos). Base de IDs tipados en `Logistics\Shared\...\AbstractId`.
- **Procesos transaccionales simultáneos**: el **outbox transaccional** (`logistics/outbox`) persiste
  los eventos en la misma transacción del cambio y los despacha asíncrono, **idempotente por
  `event_id`**, con `SELECT ... FOR UPDATE SKIP LOCKED` + lease para que múltiples workers procesen en
  paralelo sin pisarse, con reintentos/backoff y dead-letter. Nada de I/O externo síncrono en el request.
- **Microservicios cuando el volumen lo justifique**: extraer un contexto = mover su package y poner el
  outbox/CQRS sobre transporte de red; no reescribir. No se parte a microservicios antes de tiempo (ADR 001).

Guía operativa para construir un contexto nuevo con este patrón: skill de proyecto
`.claude/skills/bounded-context/SKILL.md`.

## Reglas que no se negocian

1. Terminado = tests verdes + PHPStan nivel 8 limpio + Pint limpio + verificación real contra los
   containers (no solo tests). Reportar números: tests y aserciones.
2. No hacer `git commit` ni `git push` sin que el usuario lo pida en ese turno.
3. Un package nuevo se registra en `bootstrap/providers.php` Y se agrega a
   `extra.laravel.dont-discover` del composer.json raíz, en el mismo cambio.
4. `php artisan filament:install` (y otros instaladores) reescriben `bootstrap/providers.php`:
   revisar y restaurar el orden después.
5. Admin Filament: todo texto visible en inglés, sin spanglish. Todo `Select::make` lleva
   `->native(false)` y, si referencia otra entidad, es un select buscable con datos reales.
6. Comentarios y documentación en español.
7. Si se cachea un objeto de dominio, se cachea su forma primitiva (array), nunca el objeto
   serializado.
8. Los tests corren sobre `logistics_test` (forzado en phpunit.xml con `<server force>`).
   `TestDatabaseIsolationTest` vigila que nunca toquen la base de desarrollo.

## Etiquetas QR

El formato de firma es un contrato con la app Flutter. Si cambia `LabelPayload::canonical()` o
el JSON del QR: subir `v`, regenerar el vector con
`php packages/logistics/labels/tests/Support/generate-test-vector.php` y copiarlo idéntico a
`logistics-app/test/fixtures/label_test_vector.json`.

## Quality gates de CI (SonarCloud / PHPStan / Pint)

Antes de tocar algo que el CI revise, leer `docs/ci-quality-gates.md`. Lo esencial:

- Cerrar el PR en verde en **un solo lote**; no pushear un arreglo por vez.
- El Quality Gate de SonarCloud **solo** falla por **Duplication on New Code > 3%** y **Reliability
  Rating < A**. Lo demás (TODO, conteo de parámetros/métodos, atributos HTML deprecados,
  "immediate return", literal duplicado) son issues de mantenibilidad que **no** bloquean el gate.
- El Análisis Automático lee **`.sonarcloud.properties`**, NO `sonar-project.properties`. Las
  exclusiones de CPD y los `issue.ignore` van ahí (o en la UI: Analysis Scope).
- La duplicación de este repo es intencional (adaptadores HTTP/Filament por contexto, autónomos
  ADR 001/008): se **excluye de CPD**, no se refactoriza (unificar acoplaría contextos).
- Evitar el token `todo` suelto en comentarios: Sonar lo marca como tarea TODO (usar `cada`, `completo`).
- `DB::table()->first()` → tipar el método como `?\stdClass` (no `object{...}`, no `@var` temporal).

## Iconos del admin (Filament v4)

Para crear/animar iconos custom en el panel, leer `docs/filament-iconos-custom-animados.md`:
catálogo semántico de Heroicons, registro de iconos SVG propios (regla CERO CDN, self-host en
`resources/svg/`) y animación vía CSS en el tema compilado `resources/css/filament/admin/theme.css`.
El panel usa SOLO el set propio `ds-*` (animación del ícono completo); flujo corto en el skill
`.claude/skills/distrack-ds-icons/SKILL.md`.
