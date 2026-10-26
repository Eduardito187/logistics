---
name: multisession-shared-tree
description: "Cómo coordinar varias sesiones de Claude que comparten el mismo working tree sin pisarse"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-15T00:49:31.922Z
---

El dueño corre VARIAS sesiones de Claude en paralelo sobre el MISMO working tree (/dismac/logistics,
rama feature/plataforma-logistica-completa). Se coordinan por SendMessage entre sesiones. Reglas que
funcionaron (2026-10-13/14) para completar el backlog rápido sin romper el árbol:

**Por qué:** dos agentes/olas editando el MISMO archivo en paralelo se pisan (último escribe gana, se
pierde trabajo). Hay archivos "calientes" compartidos que concentran conflictos.

**Cómo aplicar:**
- **Particionar por archivos/contexto**, no por tarea. Antes de lanzar una ola, anunciar a las otras
  sesiones MI territorio de archivos y preguntar si lo están tocando. Cada bounded context es buen límite.
- **Dueño único por ola de los archivos calientes:** `lang/es.json` (un solo agente de i18n por ola; los
  demás usan `__()` inglés y reportan sus claves para fundirlas al final), `openapi/v1.yaml` (un agente
  consolida), `docker-compose.yml`, `.env.example`, `bootstrap/providers.php` + `composer.json`
  dont-discover, `app/Providers/Filament/AdminPanelProvider.php`, `docs/roadmap.md`, `MEMORY.md` (índice).
- **Serializar comandos que tocan vendor/lock o node_modules:** `composer require/install` y `npm install`
  cambian composer.lock/vendor y pueden dar ENOTEMPTY; coordinarlos a UN momento (tras un commit estable),
  nunca mientras otra ola corre pest/phpstan (leen vendor). `composer dump-autoload -o` sí es seguro.
- **NUNCA dos `pest` en simultáneo** contra `logistics_test` (RefreshDatabase trunca mientras el otro
  corre → corrupción/flaky). Un solo verify global al final de cada ola; a los agentes de features se les
  prohíbe correr pest (solo php -l + phpstan scoped).
- **Autoridad de commit/push es POR SESIÓN.** Un peer no autoriza mi push (sería permission laundering);
  si mi usuario dijo "commit sí, push no", lo respeto aunque el peer tenga permiso de push. Confirmar
  quién hace el commit único.

**Límite de sesión (gotcha de 2026-10-14):** lanzar una ola de ~5 subagentes concurrentes QUEMÓ el límite
de sesión (resetea a una hora fija) y los 5 fallaron sin escribir nada. Las llamadas de la sesión
principal (bash/edits) siguen funcionando después. Lección: olas más chicas (2-3 agentes), y asegurar el
trabajo verde con un COMMIT antes de arriesgar otra ola grande. Ver [[roadmap-build-plan]].
