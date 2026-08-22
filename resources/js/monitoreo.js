/**
 * Panel de Monitoreo (spec 073) — pantalla con permiso propio, DataTables server-side por bloque,
 * modales AJAX y Toastr. Cada bloque se aísla: si uno falla, no tumba la pantalla (FR-024).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[monitoreo] jQuery no está disponible.');
        return;
    }

    const cfg = window.MonitoreoConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }
    function toast(tipo, mensaje, titulo) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, titulo || '');
        } else {
            console.log('[monitoreo][' + tipo + ']', mensaje);
        }
    }

    const idiomaDt = {
        search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros', processing: 'Cargando...',
        info: 'Mostrando _START_ a _END_ de _TOTAL_', infoEmpty: 'Sin datos',
        infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'Sin resultados',
        paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
    };

    function numero(v, decimales) {
        if (v === null || v === undefined || v === '') { return '—'; }
        return Number(v).toLocaleString('es-AR', { minimumFractionDigits: decimales || 0, maximumFractionDigits: decimales || 0 });
    }
    function money(v) {
        if (v === null || v === undefined) { return '—'; }
        return '$ ' + Number(v || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function escapar(texto) {
        return $('<div>').text(texto === null || texto === undefined ? '' : String(texto)).html();
    }
    // Nombres de producto/título largos no deben empujar el resto de las columnas: se truncan
    // con puntos suspensivos y el texto completo queda disponible al pasar el mouse.
    function truncar(texto, maxPx) {
        const seguro = escapar(texto);
        return '<span class="d-inline-block text-truncate align-middle" style="max-width:' + (maxPx || 260) + 'px" title="' + seguro + '">' + seguro + '</span>';
    }

    // Cada tabla que arma esta pantalla, para poder refrescarlas todas juntas.
    const tablas = [];

    // Sólo los bloques accionables llevan el punto de color en su pestaña — los informativos
    // (sin stock, órdenes, ventas) no compiten por atención con los que hay que resolver.
    const DOT_SEVERIDAD = { publicaciones: 'dot-critico', reponer: 'dot-atencion', 'riesgo-ml': 'dot-atencion' };

    function armarTabla(selector, ajaxUrl, columnas, extra) {
        const $tabla = $(selector);
        if (!$tabla.length || !ajaxUrl) { return null; }

        const clave = selector.replace('#tabla-', '');

        const tabla = $tabla.DataTable(Object.assign({
            processing: true,
            serverSide: true,
            // `autoWidth` calculado por DataTables antes de tener datos reales (server-side) es
            // justo lo que descuadraba las columnas: con esto en `false`, el ancho lo deciden el
            // `width` de cada columna de abajo y el contenido, sin ese primer cálculo a ciegas.
            autoWidth: false,
            language: idiomaDt,
            ajax: {
                url: ajaxUrl,
                error: function () {
                    $('[data-error="' + clave + '"]').removeClass('d-none');
                    $tabla.closest('.js-bloque-body').find('.table-responsive').addClass('d-none');
                },
                dataSrc: function (json) {
                    $('[data-error="' + clave + '"]').addClass('d-none');
                    $tabla.closest('.js-bloque-body').find('.table-responsive').removeClass('d-none');
                    const total = json.recordsTotal || 0;
                    $('[data-vacio="' + clave + '"]').toggleClass('d-none', total > 0);
                    $('#conteo-' + clave).text(total).toggleClass('d-none', total === 0);
                    if (DOT_SEVERIDAD[clave]) {
                        $('#dot-' + clave).toggleClass(DOT_SEVERIDAD[clave], total > 0);
                    }
                    return json.data;
                },
            },
            columns: columnas,
        }, extra || {}));

        tablas.push(tabla);
        return tabla;
    }

    // ====================== PULSO ======================
    function cargarPulso() {
        $.getJSON(rutas.pulso).done(function (d) {
            $('#pulso-error').addClass('d-none');
            $('#pulso-contenido').removeClass('d-none');
            $('#pulso-servidor').text(d.servidor || '—');

            function pintarSync(prefijo, datos) {
                const texto = datos.hace === null ? 'Nunca corrió' : ('hace ' + datos.hace + ' min');
                $('#pulso-' + prefijo).text(texto).toggleClass('text-danger', !!datos.alerta).toggleClass('text-success', !datos.alerta);
            }
            pintarSync('sync-ordenes', d.sincronizacion.ordenes);
            pintarSync('sync-stock', d.sincronizacion.stock);

            $('#pulso-publicaciones').text(numero(d.conteos.publicaciones));

            if (!d.mlConfigurado) {
                $('#tab-publicaciones, #tab-riesgo-ml, #tab-sin-stock, #tab-ordenes').each(function () {
                    $(this).prepend(
                        '<div class="alert alert-secondary py-2 mb-3">Mercado Libre no está conectado o sin depósito configurado: este bloque no tiene datos.</div>'
                    );
                });
            }
        }).fail(function () {
            $('#pulso-error').removeClass('d-none');
            $('#pulso-contenido').addClass('d-none');
        });
    }

    // ====================== PUBLICACIONES ======================
    const tablaPublicaciones = armarTabla('#tabla-publicaciones', rutas.publicaciones, [
        { data: 'item', name: 'p.ml_item_id', width: '11%' },
        { data: 'titulo', name: 'p.titulo_ml', render: (v) => truncar(v, 260) },
        { data: 'stock', name: 'stock', render: (v) => numero(v, 2), width: '7%', className: 'text-end' },
        { data: 'publicado', name: 'p.ultimo_stock_publicado', render: (v) => numero(v, 0), width: '7%', className: 'text-end' },
        { data: 'intentos', name: 'p.stock_intentos_fallidos', width: '7%', className: 'text-end' },
        { data: 'desde', name: 'p.stock_error_desde', width: '9%' },
        {
            data: 'error', name: 'p.stock_error', orderable: false, width: '25%',
            render: function (v, tipo, fila) {
                const texto = truncar(v, 260);
                return fila.moderacion
                    ? '<span class="badge bg-secondary" title="Frenado por la moderación de Mercado Libre">Moderación ML</span> ' + texto
                    : texto;
            },
        },
        {
            data: null, orderable: false, searchable: false, width: '15%',
            render: function (fila) {
                if (!cfg.puedeGestionar) { return ''; }
                if (fila.moderacion) { return '<span class="text-muted small">Sin acción posible</span>'; }
                return '' +
                    '<button type="button" class="btn btn-sm btn-outline-primary js-destrabar" data-item="' + fila.item + '">Destrabar</button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary js-reactivar" data-item="' + fila.item + '">Reactivar</button>';
            },
        },
    ]);

    // ====================== A REPONER ======================
    const tablaReponer = armarTabla('#tabla-reponer', rutas.reponer, [
        { data: 'codigo', name: 'p.codigo', render: (v) => truncar(v, 110), width: '12%' },
        { data: 'nombre', name: 'p.nombre', render: (v) => truncar(v, 320) },
        { data: 'stockLocal', name: 'stock_local', render: (v) => numero(v, 2), width: '8%', className: 'text-end' },
        {
            data: null, orderable: false, searchable: false, width: '8%', className: 'text-end',
            render: (fila) => numero(fila.stockFull, 2) + (fila.stockFull > 0 ? ' <span class="text-muted small" title="Tiene stock en Full: por eso no está en riesgo de publicación">ⓘ</span>' : ''),
        },
        { data: 'puntoReposicion', name: 'p.punto_reposicion', width: '8%', className: 'text-end' },
        { data: 'faltan', name: 'faltan', orderable: false, width: '8%', className: 'text-end' },
        { data: 'proveedor', name: 'pv.nombre', render: (v) => truncar(v || '—', 160), width: '14%' },
        {
            data: null, orderable: false, searchable: false, width: '6%', className: 'text-center',
            render: function (fila) {
                if (!cfg.puedeGestionar) { return ''; }
                return '<button type="button" class="btn btn-sm btn-outline-primary js-editar-punto"'
                    + ' data-id="' + fila.id + '" data-nombre="' + escapar(fila.nombre) + '" data-valor="' + fila.puntoReposicion + '">'
                    + '<i class="fas fa-pen"></i></button>';
            },
        },
    ]);

    // ====================== RIESGO ML ======================
    const tablaRiesgoMl = armarTabla('#tabla-riesgo-ml', rutas.riesgoMl, [
        { data: 'nombre', name: 'nombre', render: (v) => truncar(v, 280) },
        { data: 'item', name: 'item', width: '12%' },
        { data: 'stockLocal', name: 'stockLocal', render: (v) => numero(v, 2), width: '9%', className: 'text-end' },
        { data: 'stockFull', name: 'stockFull', render: (v) => numero(v, 2), width: '9%', className: 'text-end' },
        { data: 'stockVendible', name: 'stockVendible', render: (v) => numero(v, 2), width: '9%', className: 'text-end' },
        { data: 'puntoReposicion', name: 'puntoReposicion', width: '9%', className: 'text-end' },
        { data: 'porDia', name: 'porDia', render: (v) => numero(v, 2), width: '9%', className: 'text-end' },
        { data: 'dias', name: 'dias', render: (v) => (v === null ? 'No rota' : numero(v, 1)), width: '9%', className: 'text-end' },
    ], { order: [] });

    // ====================== SIN STOCK ======================
    const tablaSinStock = armarTabla('#tabla-sin-stock', rutas.sinStock, [
        { data: 'nombre', name: 'p.nombre', render: (v) => truncar(v, 420) },
        { data: 'item', name: 'm.ml_item_id', width: '18%' },
        { data: 'local', name: 'local', render: (v) => numero(v, 2), width: '10%', className: 'text-end' },
        { data: 'full', name: 'full', render: (v) => numero(v, 2), width: '10%', className: 'text-end' },
    ]);

    // ====================== ÓRDENES SIN VENTA ======================
    const tablaOrdenes = armarTabla('#tabla-ordenes', rutas.ordenes, [
        { data: 'orden', name: 'orden', width: '14%' },
        { data: 'comprador', name: 'comprador', render: (v) => truncar(v, 160), width: '14%' },
        { data: 'total', name: 'total', render: (v) => money(v), width: '10%', className: 'text-end' },
        { data: 'cuando', name: 'cuando', width: '10%' },
        {
            data: 'estado', name: 'estado',
            render: function (v, tipo, fila) {
                const clase = fila.accionable ? 'bg-danger' : 'bg-secondary';
                return '<span class="badge ' + clase + '">' + escapar(v) + '</span>';
            },
        },
        {
            data: 'causa', name: 'causa', orderable: false,
            render: function (v, tipo, fila) {
                let extra = '';
                if (fila.fraude) { extra += ' <span class="badge bg-danger">Fraude</span>'; }
                if (fila.mediacion) { extra += ' <span class="badge bg-warning text-dark">Mediación</span>'; }
                return escapar(v) + extra;
            },
        },
    ], { order: [] });

    // ====================== ÚLTIMAS VENTAS (lista fija, no DataTables) ======================
    function cargarVentas() {
        if (!rutas.ventas) { return; }
        $.getJSON(rutas.ventas).done(function (filas) {
            $('[data-error="ventas"]').addClass('d-none');
            $('[data-vacio="ventas"]').toggleClass('d-none', filas.length > 0);
            const $tbody = $('#tbody-ventas').empty();
            filas.forEach(function (v) {
                $tbody.append(
                    '<tr><td>#' + v.id + '</td><td>' + escapar(v.origen) + '</td><td>' + money(v.total) + '</td>'
                    + '<td>' + escapar(v.deposito) + '</td><td>' + escapar(v.cuando) + '</td>'
                    + '<td>' + v.movimientos + '</td><td>' + numero(v.neto, 2) + '</td></tr>'
                );
            });
        }).fail(function () {
            $('[data-error="ventas"]').removeClass('d-none');
        });
    }

    function refrescarTodo() {
        cargarPulso();
        cargarVentas();
        tablas.forEach(function (t) { t.ajax.reload(null, false); });
    }

    // ====================== ACCIONES ======================
    function accionSimple(ruta, body, tabla) {
        $.post(ruta, body).done(function (resp) {
            toast(resp.ok ? 'success' : 'error', resp.mensaje);
            if (resp.ok && tabla) { tabla.ajax.reload(null, false); }
            if (resp.ok) { cargarPulso(); }
        }).fail(function (xhr) {
            const msg = xhr.responseJSON && xhr.responseJSON.mensaje ? xhr.responseJSON.mensaje : 'No se pudo completar la acción.';
            toast('error', msg);
        });
    }

    $(document).on('click', '.js-destrabar', function () {
        accionSimple(rutas.destrabar, { ml_item_id: $(this).data('item') }, tablaPublicaciones);
    });
    $(document).on('click', '.js-reactivar', function () {
        accionSimple(rutas.reactivar, { ml_item_id: $(this).data('item') }, tablaPublicaciones);
    });
    $('#btn-refrescar-todo').on('click', refrescarTodo);

    // ====================== MODAL: editar punto de reposición ======================
    const $modalPunto = $('#modal-punto-reposicion');
    const modalPunto = window.bootstrap && $modalPunto.length ? new window.bootstrap.Modal($modalPunto[0]) : null;

    $(document).on('click', '.js-editar-punto', function () {
        if (!modalPunto) { return; }
        $('#punto-reposicion-producto-id').val($(this).data('id'));
        $('#punto-reposicion-producto-nombre').text($(this).data('nombre'));
        $('#punto-reposicion-valor').val($(this).data('valor') || '');
        $('#form-punto-reposicion .is-invalid').removeClass('is-invalid');
        $('#form-punto-reposicion [data-field]').text('');
        modalPunto.show();
    });

    $('#form-punto-reposicion').on('submit', function (e) {
        e.preventDefault();
        const $form = $(this);
        const body = {
            producto_id: $('#punto-reposicion-producto-id').val(),
            punto_reposicion: $('#punto-reposicion-valor').val() || null,
        };
        $form.find('[data-field]').text('');
        $form.find('.is-invalid').removeClass('is-invalid');

        $.post(rutas.puntoReposicion, body).done(function (resp) {
            toast('success', resp.mensaje);
            modalPunto && modalPunto.hide();
            tablaReponer && tablaReponer.ajax.reload(null, false);
            cargarPulso();
        }).fail(function (xhr) {
            if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                Object.keys(xhr.responseJSON.errors).forEach(function (campo) {
                    $form.find('[name="' + campo + '"]').addClass('is-invalid');
                    $form.find('[data-field="' + campo + '"]').text(xhr.responseJSON.errors[campo][0]);
                });
                return;
            }
            toast('error', 'No se pudo guardar el punto de reposición.');
        });
    });

    // ====================== Tabs: recalcular anchos de columna al mostrar ======================
    // Una DataTable inicializada mientras su pestaña no está activa (display:none) calcula mal
    // el ancho de las columnas. `columns.adjust()` la corrige recién cuando el bloque se hace visible.
    $('#monitoreo-tabs').on('shown.bs.tab', function (e) {
        const destino = $(e.target).data('bs-target');
        const $tabla = $(destino).find('table.dataTable');
        if ($tabla.length && $.fn.DataTable.isDataTable($tabla)) {
            $tabla.DataTable().columns.adjust();
        }
    });

    // ====================== Posicionar en el bloque elegido desde la barra superior (FR-028) ======================
    $(function () {
        if (cfg.bloque) {
            const $boton = $('#tab-btn-' + cfg.bloque);
            if ($boton.length) {
                setTimeout(function () {
                    (new bootstrap.Tab($boton[0])).show();
                    $('#monitoreo-tabs')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 300);
            }
        }
        refrescarTodo();
    });

    // Red de contención: si algo (fuentes, CSS) terminó de asentar el layout después de que las
    // tablas ya calcularon su ancho, esto las vuelve a alinear una vez que la página está 100% lista.
    $(window).on('load', function () {
        tablas.forEach(function (t) { t.columns.adjust(); });
    });
})();
