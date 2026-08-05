/**
 * Fix global de scroll horizontal en tablas DataTables.
 *
 * Problema: DataTables inyecta TODO su wrapper (length + filtro, la <table>,
 * info + paginación) dentro del mismo `.table-responsive` que en el Blade
 * sólo envuelve la <table>. Como `.table-responsive` es el que tiene
 * `overflow-x:auto`, al scrollear para ver columnas de la derecha se
 * arrastra también la paginación (queda descuadrada / hay que scrollear para
 * volver a verla).
 *
 * Fix: al iniciarse cada DataTable, se saca el overflow del `.table-responsive`
 * grande y se envuelve sólo la <table> en un div interno que es el que
 * scrollea. length/filtro/info/paginación quedan afuera, siempre visibles y
 * en su lugar. Aplica automáticamente a todas las tablas del proyecto sin
 * tener que tocar cada vista.
 */
(function () {
    'use strict';

    var $ = window.jQuery;
    if (!$) {
        return;
    }

    $(document).on('init.dt', function (e) {
        if (e.namespace !== 'dt') {
            return;
        }

        var $table = $(e.target);
        var $outer = $table.closest('.table-responsive');
        if (!$outer.length || $outer.data('dtScrollFixApplied')) {
            return;
        }

        $outer.data('dtScrollFixApplied', true);
        $outer.css('overflow-x', 'visible');
        $table.wrap('<div class="dt-scroll-x"></div>');
    });
})();
