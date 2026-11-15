// Cabecera `Server` de cada respuesta: nginx siempre la envía con su nombre y no trae una directiva para
// quitarla. Este filtro la reemplaza por el nombre del producto, así ninguna respuesta (páginas, API,
// errores, WebSocket) delata el software del servidor. Lo aplica snippets/server-header.conf en cada
// `location`; requiere el módulo njs que ya viene en la imagen oficial (se carga al arrancar nginx).
function clean(r) {
    r.headersOut['Server'] = 'DisTrack';
}

export default { clean };
