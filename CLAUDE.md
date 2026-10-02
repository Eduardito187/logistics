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
