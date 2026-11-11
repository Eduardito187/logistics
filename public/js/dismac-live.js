/**
 * Helper compartido para paneles "en vivo" (polling, mapas Leaflet, Echo) dentro de bloques @script de
 * Livewire en el admin Filament.
 *
 * Problema que resuelve: el panel corre en modo SPA (`->spa()` → wire:navigate). Al navegar a otra
 * página el componente Livewire se destruye, pero un `setInterval` suelto sigue vivo: el `$wire` del
 * script ya no encuentra su componente (Livewire borra `el.__livewire` y la magia `$wire` devuelve un
 * no-op `() => {}`), así que `$wire.metodo()` devuelve `undefined` y `.then(...)` revienta con
 * "Cannot read properties of undefined (reading 'then')" en cada tick. Además, cada vuelta a la página
 * creaba OTRO intervalo y OTRO mapa Leaflet (fuga de memoria/CPU y peticiones duplicadas).
 *
 * Uso en un @script (tras cargar este archivo vía @assets):
 *
 *   const live = window.DismacLive.session({ el, component: $wire.__instance });
 *   live.onDispose(() => map.remove());                         // liberar el mapa al salir
 *   live.timeout(300, () => map.invalidateSize());              // setTimeout que se cancela al salir
 *   live.call(() => $wire.positions(), (fresh) => { ... });     // llamada segura (nunca Uncaught)
 *   live.every(10000, () => live.call(() => $wire.positions(), render)); // polling sin fugas
 *
 * La sesión se libera (una sola vez) cuando ocurre lo primero de:
 *   - `livewire:navigating` (el usuario sale con navegación SPA; se dispara ANTES del swap y de la
 *     foto del HTML para el botón "atrás", así el mapa no queda congelado en esa foto),
 *   - la limpieza del componente Livewire (si se pasa `component`),
 *   - el elemento raíz (`el`) ya no está en el documento (verificado en cada tick).
 */
(function () {
    'use strict';

    // Log discreto: los errores de red/servidor del polling no deben ensuciar la consola como Uncaught.
    function debug(message, error) {
        if (window.console && typeof window.console.debug === 'function') {
            window.console.debug('DismacLive: ' + message, error);
        }
    }

    function session(options) {
        options = options || {};
        var el = options.el || null;
        var alive = true;
        var intervals = [];
        var timeouts = [];
        var disposers = [];
        var visibilityHandlers = [];

        function isAlive() {
            if (!alive) {
                return false;
            }
            // El elemento raíz salió del DOM (swap de página u otro motivo): liberar todo.
            if (el && el.isConnected === false) {
                dispose();
                return false;
            }
            return true;
        }

        function dispose() {
            if (!alive) {
                return;
            }
            alive = false;
            document.removeEventListener('livewire:navigating', dispose);
            intervals.forEach(function (id) { window.clearInterval(id); });
            timeouts.forEach(function (id) { window.clearTimeout(id); });
            visibilityHandlers.forEach(function (fn) { document.removeEventListener('visibilitychange', fn); });
            intervals = [];
            timeouts = [];
            visibilityHandlers = [];
            // Los disposers se corren en orden inverso (lo último creado se libera primero).
            while (disposers.length) {
                var fn = disposers.pop();
                try { fn(); } catch (e) { debug('error al liberar un recurso', e); }
            }
        }

        // Registra un recurso a liberar al salir (p. ej. `map.remove()`, `stopListening` de Echo).
        function onDispose(fn) {
            if (typeof fn !== 'function') {
                return;
            }
            if (!alive) {
                try { fn(); } catch (e) { debug('error al liberar un recurso', e); }
                return;
            }
            disposers.push(fn);
        }

        // Llamada segura a un método del componente: tolera que `$wire.metodo()` NO devuelva una
        // promesa (componente destruido → undefined), captura errores de red/servidor y descarta el
        // resultado si la sesión se liberó mientras la petición estaba en vuelo. Nunca rechaza.
        function call(invoke, onResult, onError) {
            if (!isAlive()) {
                return Promise.resolve(undefined);
            }
            var result;
            try {
                result = invoke();
            } catch (e) {
                result = Promise.reject(e);
            }
            // Una llamada viva de Livewire SIEMPRE devuelve una promesa. Si no (componente ya sin raíz:
            // la magia `$wire` devuelve un no-op), no hay respuesta real: se ignora en vez de pintar vacío.
            if (!result || typeof result.then !== 'function') {
                debug('la llamada no devolvió una promesa (¿componente destruido?); se ignora', result);
                return Promise.resolve(undefined);
            }
            return Promise.resolve(result).then(function (value) {
                if (!isAlive() || typeof onResult !== 'function') {
                    return value;
                }
                onResult(value);
                return value;
            }).catch(function (e) {
                debug('falló una llamada en vivo', e);
                if (isAlive() && typeof onError === 'function') {
                    try { onError(e); } catch (e2) { debug('falló el manejador de error', e2); }
                }
                return undefined;
            });
        }

        // setTimeout que se cancela al liberar la sesión.
        function timeout(ms, fn) {
            if (!isAlive()) {
                return null;
            }
            var id = window.setTimeout(function () {
                timeouts = timeouts.filter(function (t) { return t !== id; });
                if (isAlive()) {
                    try { fn(); } catch (e) { debug('falló un temporizador', e); }
                }
            }, ms);
            timeouts.push(id);
            return id;
        }

        // Polling: ejecuta `task` cada `ms`. No se solapa (si la vuelta anterior sigue en vuelo, se salta
        // el tick), no pide datos con la pestaña oculta y, al volver a verla, refresca enseguida.
        function every(ms, task) {
            if (!isAlive()) {
                return;
            }
            var running = false;
            var tick = function () {
                if (!isAlive() || running || document.hidden) {
                    return;
                }
                running = true;
                var done = function () { running = false; };
                try {
                    Promise.resolve(task()).then(done, function (e) { done(); debug('falló un ciclo de polling', e); });
                } catch (e) {
                    done();
                    debug('falló un ciclo de polling', e);
                }
            };
            intervals.push(window.setInterval(tick, ms));
            var onVisible = function () {
                if (!document.hidden) {
                    tick();
                }
            };
            document.addEventListener('visibilitychange', onVisible);
            visibilityHandlers.push(onVisible);
        }

        // Salida por navegación SPA. Se desregistra en dispose(), así no se acumulan listeners.
        document.addEventListener('livewire:navigating', dispose);

        // Limpieza del componente Livewire (cuando lo destruye Livewire por cualquier motivo).
        var component = options.component;
        if (component && typeof component.addCleanup === 'function') {
            try { component.addCleanup(dispose); } catch (e) { debug('no se pudo enganchar la limpieza del componente', e); }
        }

        return {
            get alive() { return isAlive(); },
            call: call,
            every: every,
            timeout: timeout,
            onDispose: onDispose,
            dispose: dispose,
        };
    }

    window.DismacLive = { session: session };
})();
