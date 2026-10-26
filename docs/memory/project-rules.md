---
name: project-rules
description: Reglas no negociables del proyecto logistics (gotchas que causan errores)
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-13T06:10:09.516Z
---

Reglas duras de CLAUDE.md. Varias son gotchas fáciles de romper:

1. **Terminado** = tests verdes + PHPStan nivel 8 limpio + Pint limpio + verificación real contra los containers (no solo tests). Siempre reportar números: tests y aserciones.
2. **No hacer `git commit` ni `git push`** salvo que el usuario lo pida en ese turno.
3. Package nuevo: registrar en `bootstrap/providers.php` **Y** agregar a `extra.laravel.dont-discover` del `composer.json` raíz, en el mismo cambio.
4. `php artisan filament:install` y otros instaladores **reescriben `bootstrap/providers.php`**: revisar y restaurar el orden después.
5. Admin Filament: todo texto visible **en inglés**, sin spanglish. Todo `Select::make` lleva `->native(false)`; si referencia otra entidad, es un select buscable con datos reales.
6. **Comentarios y documentación en español**; texto visible del admin en inglés.
7. Si se cachea un objeto de dominio, se cachea su **forma primitiva (array)**, nunca el objeto serializado.
8. Tests corren sobre `logistics_test` (forzado en phpunit.xml con `<server force>`); `TestDatabaseIsolationTest` vigila que nunca toquen la base de desarrollo.
9. Todo endpoint nuevo se documenta en `openapi/v1.yaml` en el mismo cambio.

**Etiquetas QR**: el formato de firma es contrato con la app Flutter. Si cambia `LabelPayload::canonical()` o el JSON del QR: subir `v`, regenerar el vector con `php packages/logistics/labels/tests/Support/generate-test-vector.php` y copiarlo idéntico a `logistics-app/test/fixtures/label_test_vector.json`. Ver [[project-tooling]].
