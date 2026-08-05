/**
 * Fix: los dropdowns de fila (menú de Acciones) que abren DENTRO de
 * `.dt-scroll-x` (el wrapper de scroll horizontal de las tablas, ver
 * datatables-scroll-fix.js) quedaban recortados verticalmente cuando la
 * tabla tenía pocas filas.
 *
 * Motivo: `.dt-scroll-x` tiene `overflow-x: auto`. El navegador NO permite
 * un eje en `auto` y el otro en `visible` — si se pide `overflow-x: auto`
 * sin tocar `overflow-y`, el valor usado de `overflow-y` pasa a ser `auto`
 * también (spec CSS Overflow). Eso convierte a `.dt-scroll-x` en un
 * contenedor con scroll/clip en AMBOS ejes, y el `.dropdown-menu` de
 * Bootstrap (position:absolute, posicionado por Popper) queda recortado
 * por ese clip si la fila está cerca del borde inferior de la tabla.
 *
 * Fix: cada botón toggle de estos dropdowns lleva `data-bs-display="static"`
 * (ver *_row_actions.blade.php) — eso apaga Popper.js en Bootstrap 5.1 y
 * deja el posicionamiento 100% manual, así este script no compite con
 * Popper por el `top/left/transform` inline del menú (si Popper sigue
 * activo, los dos sistemas se pisan y el menú termina en cualquier lado).
 *
 * Con Popper apagado: al abrir, se saca el `.dropdown-menu` del flujo (se
 * mueve a `<body>`, position:fixed, coordenadas calculadas a mano contra
 * el botón) y se lo devuelve a su lugar original al cerrarlo.
 */
(function () {
    'use strict';

    var $ = window.jQuery;
    if (!$) {
        return;
    }

    function reposicionar($menu, $toggle) {
        var rect = $toggle[0].getBoundingClientRect();
        // `display:block` con position:fixed y left/right ambos "auto" DEBERÍA
        // achicarse al contenido (shrink-to-fit) — en la práctica, movido a
        // `<body>` sin el contexto original del `.dropdown`, terminaba
        // estirándose a lo ancho de la página. `inline-block` sí achica
        // siempre, así que se mide con eso y se fija el ancho en px.
        $menu.css('display', 'inline-block');
        var menuWidth = $menu.outerWidth();
        var menuHeight = $menu.outerHeight();
        $menu.css({ display: 'block', width: menuWidth + 'px' });

        var alignEnd = $menu.hasClass('dropdown-menu-end');
        var left = alignEnd ? (rect.right - menuWidth) : rect.left;
        var top = rect.bottom;

        if (top + menuHeight > window.innerHeight - 4) {
            top = rect.top - menuHeight;
        }
        if (left < 4) {
            left = 4;
        }
        if (left + menuWidth > window.innerWidth - 4) {
            left = window.innerWidth - menuWidth - 4;
        }

        $menu.css({ top: top + 'px', left: left + 'px' });
    }

    // "show" (no "shown"): con Popper apagado no hay nada más tocando el
    // inline style del menú, así que podemos posicionar ya mismo. Se fuerza
    // visibility:hidden -> se mide -> se calcula -> se muestra, para no
    // parpadear en la posición vieja ni medir un elemento todavía oculto
    // (display:none mide 0x0).
    $(document).on('show.bs.dropdown', '.dt-scroll-x .dropdown', function () {
        var $dropdown = $(this);
        var $menu = $dropdown.children('.dropdown-menu');
        var $toggle = $dropdown.children('[data-bs-toggle="dropdown"]');
        if (!$menu.length || !$toggle.length) {
            return;
        }

        var $marker = $('<span style="display:none"></span>');
        $menu.before($marker);
        $('body').append($menu);
        $dropdown.data('dtMenu', $menu).data('dtMarker', $marker);

        $menu.css({
            position: 'fixed', margin: 0, zIndex: 3000,
            display: 'block', visibility: 'hidden',
        });
        reposicionar($menu, $toggle);
        $menu.css('visibility', '');

        var handler = function () { reposicionar($menu, $toggle); };
        $(window).on('scroll.dtDropdown resize.dtDropdown', handler);
        $dropdown.closest('.dt-scroll-x').on('scroll.dtDropdown', handler);
        $dropdown.data('dtHandler', handler);
    });

    $(document).on('hidden.bs.dropdown', '.dt-scroll-x .dropdown', function () {
        var $dropdown = $(this);
        var $menu = $dropdown.data('dtMenu');
        var $marker = $dropdown.data('dtMarker');
        var handler = $dropdown.data('dtHandler');

        if ($menu && $marker) {
            $menu.css({ position: '', margin: '', top: '', left: '', zIndex: '', display: '', width: '' });
            $marker.replaceWith($menu);
        }
        if (handler) {
            $(window).off('scroll.dtDropdown resize.dtDropdown', handler);
            $dropdown.closest('.dt-scroll-x').off('scroll.dtDropdown', handler);
        }
        $dropdown.removeData('dtMenu').removeData('dtMarker').removeData('dtHandler');
    });
})();
