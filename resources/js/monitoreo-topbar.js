/**
 * Barra superior de Monitoreo (spec 073): indicador de problemas + campanita de notificaciones.
 *
 * Una sola llamada a `monitoreo/resumen` alimenta los dos widgets (research Decisión 7).
 * Se refresca al cargar y cada 5 minutos con la pestaña abierta (FR-029, FR-037a).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[monitoreo-topbar] jQuery no está disponible.');
        return;
    }

    const cfg = window.MonitoreoTopbarConfig || {};
    const rutas = cfg.rutas || {};
    if (!rutas.resumen) { return; }

    const REFRESCO_MS = 5 * 60 * 1000;

    function escapar(texto) {
        return $('<div>').text(texto === null || texto === undefined ? '' : String(texto)).html();
    }
    function numero(v, decimales) {
        if (v === null || v === undefined) { return '—'; }
        return Number(v).toLocaleString('es-AR', { minimumFractionDigits: decimales || 0, maximumFractionDigits: decimales || 0 });
    }

    function pintarBadge($badge, cantidad) {
        if (cantidad > 0) {
            $badge.text(cantidad > 99 ? '99+' : cantidad).removeClass('d-none');
        } else {
            $badge.addClass('d-none');
        }
    }

    // Un renglón conciso por categoría (nunca uno por episodio) — no importa si son 3 o 300.
    const CATEGORIAS = {
        publicaciones: {
            icono: 'fa-satellite-dish', texto: 'no está actualizando su stock', textoPlural: 'no están actualizando su stock',
            bloque: 'publicaciones',
        },
        reponer: {
            icono: 'fa-boxes-stacked', texto: 'necesita revisión por punto de reposición', textoPlural: 'necesitan revisión por punto de reposición',
            bloque: 'reponer',
        },
    };

    function renglonCategoria(cantidad, sustantivo, categoria) {
        const c = CATEGORIAS[categoria];
        const texto = cantidad === 1 ? c.texto : c.textoPlural;
        const palabra = cantidad === 1 ? sustantivo.singular : sustantivo.plural;

        return '' +
            '<a href="' + rutas.panel + '?bloque=' + c.bloque + '" class="small d-flex align-items-start gap-2 text-decoration-none text-body py-2 border-bottom">' +
            '<i class="fas ' + c.icono + ' text-warning mt-1"></i>' +
            '<span>Hay <strong>' + numero(cantidad) + '</strong> ' + palabra + ' que ' + texto + '.</span>' +
            '</a>';
    }

    function pintarIndicador(d) {
        const hayAlgo = d.conteos.publicacionesFallando > 0 || d.conteos.aReponer > 0 || d.conteos.sincronizacionAlerta;
        $('#monitoreo-indicador-badge').toggleClass('d-none', !hayAlgo);
        $('#monitoreo-indicador-toggle').toggleClass('text-warning', hayAlgo);

        const $panel = $('#monitoreo-indicador-panel');
        $panel.empty();

        if (!hayAlgo) {
            $panel.append('<p class="text-muted text-center mb-0" id="monitoreo-indicador-vacio">Todo en orden.</p>');
            return;
        }

        if (d.conteos.sincronizacionAlerta) {
            $panel.append('<div class="alert alert-warning py-2 mb-2 small">Una sincronización con Mercado Libre lleva más de 15 minutos sin correr.</div>');
        }
        if (d.conteos.publicacionesFallando > 0) {
            $panel.append(renglonCategoria(d.conteos.publicacionesFallando, { singular: 'publicación de Mercado Libre', plural: 'publicaciones de Mercado Libre' }, 'publicaciones'));
        }
        if (d.conteos.aReponer > 0) {
            $panel.append(renglonCategoria(d.conteos.aReponer, { singular: 'producto', plural: 'productos' }, 'reponer'));
        }

        $panel.append('<a href="' + rutas.panel + '" class="btn btn-sm btn-primary w-100 mt-2">Ir a Monitoreo</a>');
    }

    let ultimasClavesVisibles = [];

    function pintarNotificaciones(d) {
        pintarBadge($('#notif-monitoreo-badge'), d.notificaciones.sinLeer);

        // Se siguen guardando las claves individuales de la muestra para que "Marcar todas" siga
        // marcando exactamente lo que el usuario tenía a la vista (FR-036a) — sólo cambia lo que
        // se pinta en pantalla, no el seguimiento de lectura por episodio.
        const items = d.notificaciones.items || [];
        ultimasClavesVisibles = items.map(function (i) { return i.clave; });

        const porTipo = d.notificaciones.porTipo || {};
        const $lista = $('#notif-monitoreo-lista').empty();

        if (!d.notificaciones.sinLeer) {
            $lista.append('<p class="text-muted text-center py-4 mb-0" id="notif-monitoreo-vacio">Sin notificaciones pendientes.</p>');
            return;
        }

        if (porTipo.reposicion > 0) {
            $lista.append(renglonCategoria(porTipo.reposicion, { singular: 'producto', plural: 'productos' }, 'reponer'));
        }
        if (porTipo.ml_stock > 0) {
            $lista.append(renglonCategoria(porTipo.ml_stock, { singular: 'publicación de Mercado Libre', plural: 'publicaciones de Mercado Libre' }, 'publicaciones'));
        }
    }

    function cargar() {
        $.getJSON(rutas.resumen).done(function (d) {
            pintarIndicador(d);
            pintarNotificaciones(d);
        }).fail(function () {
            // El resumen se llama desde todas las pantallas: una falla acá no debe romper nada
            // visible más allá de dejar los widgets sin actualizar.
            console.warn('[monitoreo-topbar] no se pudo cargar el resumen.');
        });
    }

    function marcar(claves, todas) {
        if (!claves.length) { return; }
        $.post(rutas.leer, { claves: claves, todas: !!todas }).done(function () {
            cargar();
        });
    }

    $(document).on('click', '.js-notif-marcar', function () {
        marcar([$(this).data('clave')]);
    });

    $(document).on('click', '#notif-monitoreo-marcar-todas', function () {
        // Marca únicamente lo que el usuario tenía a la vista, no todo lo que exista en el
        // servidor en este instante (FR-036a).
        marcar(ultimasClavesVisibles, true);
    });

    $(function () {
        cargar();
        setInterval(cargar, REFRESCO_MS);
    });
})();
