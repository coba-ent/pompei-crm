/**
 * Módulo Productos & Servicios — DataTable server-side + modales AJAX + toasts.
 *
 * Reutiliza el patrón de Clientes: jQuery, Toastr y DataTables cargados
 * globalmente por el template NexaDash (config/dz.php pagelevel 'productos').
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[productos] jQuery no está disponible.');
        return;
    }

    const cfg = window.ProductosConfig || {};
    const rutas = cfg.rutas || {};

    // Configuración global reutilizable de Toastr (misma de Clientes).
    if (window.toastr) {
        window.toastr.options = {
            closeButton: true,
            progressBar: true,
            positionClass: 'toast-top-right',
            preventDuplicates: true,
            newestOnTop: true,
            timeOut: 4000,
            extendedTimeOut: 1500,
        };
    }

    function toast(tipo, mensaje, titulo) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, titulo || '');
        } else {
            console.log('[productos][' + tipo + ']', mensaje);
        }
    }

    function esc(v) {
        return (v === null || v === undefined) ? '' : $('<div>').text(v).html();
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    $(function () {
        const $tabla = $('#tabla-productos');
        if (!$tabla.length) {
            return;
        }

        // Fix de modales apilados (Bootstrap 5): al cerrar un modal que se abrió
        // ENCIMA de otro (ej. "Nueva Lista de Precios" sobre el modal de producto),
        // Bootstrap quita `modal-open` del <body> y el modal de abajo pierde el
        // scroll. Si al cerrar uno queda otro abierto, restauramos `modal-open`.
        $(document).on('hidden.bs.modal', '.modal', function () {
            if ($('.modal.show').length) {
                $('body').addClass('modal-open');
            }
        });

        // "Sincronizar precios ahora" (spec 016, US3): reintenta el envío de precios
        // pendientes/con error hacia Mercado Libre. Mismo patrón AJAX + Toastr que el
        // resto de las acciones de este listado, sin recarga de página.
        $('#btn-sincronizar-precios-ml').on('click', function () {
            const $btn = $(this).prop('disabled', true);

            $.post(rutas.sincronizarPreciosMl)
                .done(function (resp) {
                    toast('success', resp.mensaje || 'Sincronización de precios completa.');
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo sincronizar los precios.');
                })
                .always(function () { $btn.prop('disabled', false); });
        });

        // Select2 (librería de select con buscador del template NexaDash). Se usa
        // en todos los selects de datos dinámicos (producto, depósitos).
        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }
        // Refresca la UI de Select2 tras cambiar value/opciones por código (sin
        // disparar los handlers de 'change' de la app).
        function refreshSelect2($el) {
            if (hasSelect2 && $el && $el.length && $el.hasClass('select2-hidden-accessible')) {
                $el.trigger('change.select2');
            }
        }

        // Filtros vigentes del panel — usados tanto por la DataTable como por el
        // modo "todos los que matchean el filtro" de Acciones Masivas (004).
        function filtrosActuales() {
            return {
                estado: $('#filtro-estado').val(),
                tipo: $('#filtro-tipo').val(),
                tipo_producto_id: $('#filtro-tipo-producto').val(),
                deposito_id: $('#filtro-deposito').val(),
                proveedor_id: $('#filtro-proveedor').val(),
                id: $('#filtro-id').val(),
                buscar: $('#filtro-buscar').val(),
                stock_min: $('#filtro-stock-min').val(),
                stock_max: $('#filtro-stock-max').val(),
            };
        }

        // --- DataTable server-side (US7) ---
        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            responsive: true,
            language: {
                search: 'Buscar:',
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ productos',
                infoEmpty: 'Sin productos',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron productos',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: {
                url: rutas.data,
                data: function (d) { $.extend(d, filtrosActuales()); },
            },
            columns: [
                {
                    data: null, orderable: false, searchable: false, className: 'text-center',
                    render: function (data, type, row) {
                        return '<input type="checkbox" class="form-check-input js-chk-producto" data-id="' + row.id + '">';
                    },
                },
                { data: 'id', name: 'id', className: 'text-end' },
                { data: 'nombre', name: 'nombre', render: $.fn.dataTable.render.text() },
                { data: 'codigo', name: 'codigo', defaultContent: '', render: $.fn.dataTable.render.text() },
                {
                    data: 'tipo', name: 'tipo',
                    render: function (val) {
                        return val === 'servicio'
                            ? '<span class="badge bg-info">Servicio</span>'
                            : '<span class="badge bg-primary-light text-primary">Producto</span>';
                    },
                },
                { data: 'tipo_producto', name: 'tipo_producto', orderable: false, searchable: false, defaultContent: '', render: $.fn.dataTable.render.text() },
                { data: 'proveedor', name: 'proveedor', orderable: false, searchable: false, defaultContent: '', render: $.fn.dataTable.render.text() },
                {
                    data: 'costo', name: 'costo', className: 'text-end',
                    render: function (val) { return new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(val || 0); },
                },
                {
                    data: 'precio_venta', name: 'precio_venta', className: 'text-end',
                    render: function (val) {
                        return new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(val || 0);
                    },
                },
                // Una columna por cada lista de precios activa (dinámico: si se crean o
                // borran listas, el listado las refleja sin tocar este archivo).
                ...(cfg.listasPrecio || []).map(function (lista) {
                    const campo = 'precio_lista_' + lista.id;
                    return {
                        data: campo, name: campo, orderable: false, searchable: false, className: 'text-end',
                        render: function (val) {
                            if (val === null || val === undefined) {
                                return '<span class="text-muted">—</span>';
                            }
                            return new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(val);
                        },
                    };
                }),
                { data: 'iva_venta', name: 'iva_venta', orderable: false, searchable: false, className: 'text-end' },
                { data: 'iva_compra', name: 'iva_compra', orderable: false, searchable: false, className: 'text-end' },
                {
                    data: 'stock_total', name: 'stock_total', orderable: false, searchable: false, className: 'text-end',
                    render: function (val) {
                        if (val === null || val === undefined) {
                            return '<span class="text-muted">—</span>';
                        }
                        return new Intl.NumberFormat('es-AR').format(val);
                    },
                },
                // Una columna de stock por cada depósito activo (dinámico, igual que las
                // listas de precio): el total solo no alcanza para ver de dónde sale el
                // stock, y hay módulos que trabajan contra UN depósito puntual.
                ...(cfg.depositosColumnas || []).map(function (deposito) {
                    const campo = 'stock_deposito_' + deposito.id;
                    return {
                        data: campo, name: campo, orderable: false, searchable: false, className: 'text-end',
                        render: function (val) {
                            if (val === null || val === undefined) {
                                return '<span class="text-muted">—</span>';
                            }
                            // El stock negativo se destaca: es un descuadre que hay que corregir.
                            const formateado = new Intl.NumberFormat('es-AR').format(val);
                            return val < 0 ? '<span class="text-danger fw-bold">' + formateado + '</span>' : formateado;
                        },
                    };
                }),
                {
                    data: 'descripcion_si', name: 'descripcion_si', orderable: false, searchable: false, className: 'text-center',
                    render: function (val) {
                        return val === 'SI'
                            ? '<span class="badge bg-success-light text-success">SÍ</span>'
                            : '<span class="text-muted">NO</span>';
                    },
                },
                {
                    data: 'imagen_si', name: 'imagen_si', orderable: false, searchable: false, className: 'text-center',
                    render: function (val) {
                        return val === 'SI'
                            ? '<span class="badge bg-success-light text-success">SÍ</span>'
                            : '<span class="text-muted">NO</span>';
                    },
                },
                {
                    data: 'activo', name: 'activo', orderable: false, searchable: false,
                    render: function (val) {
                        return val
                            ? '<span class="badge bg-primary">Activo</span>'
                            : '<span class="badge bg-light text-dark">Inactivo</span>';
                    },
                },
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'text-end' },
            ],
            order: [[1, 'asc']],
        });

        // ================== SELECCIÓN MÚLTIPLE + ACCIONES MASIVAS (004) ==================
        // Selección 100% en memoria del cliente (sin extensión "Select" de DataTables,
        // research.md §1): un Set de IDs de la página visible + un flag para "todos los
        // que matchean el filtro" (FR-003/FR-004). Se limpia en cada nuevo pedido AJAX
        // de la tabla (cambio de página, filtro, orden o búsqueda).
        let selectedIds = new Set();
        let seleccionarTodos = false;

        function totalFiltrado() {
            return tabla.page.info().recordsDisplay;
        }

        function actualizarBarraSeleccion() {
            const cantidad = seleccionarTodos ? totalFiltrado() : selectedIds.size;
            const $barra = $('#barra-seleccion');
            if (cantidad === 0) {
                $barra.addClass('d-none').removeClass('d-flex');
                return;
            }
            $barra.removeClass('d-none').addClass('d-flex');
            $('#barra-seleccion-cantidad').text(cantidad);
            const $todos = $('#barra-seleccion-todos');
            if (!seleccionarTodos && selectedIds.size < totalFiltrado()) {
                $todos.removeClass('d-none').text('Seleccionar los ' + totalFiltrado() + ' productos.');
            } else {
                $todos.addClass('d-none');
            }
        }

        function limpiarSeleccion() {
            selectedIds.clear();
            seleccionarTodos = false;
            $('#chk-seleccionar-todo').prop('checked', false);
            actualizarBarraSeleccion();
        }

        // Cualquier nuevo pedido server-side (página, filtro, orden, búsqueda, o el
        // refresh tras aplicar una acción masiva) limpia la selección (FR-004).
        tabla.on('preXhr.dt', limpiarSeleccion);

        $tabla.on('change', '.js-chk-producto', function () {
            const id = parseInt($(this).data('id'), 10);
            if (this.checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
                seleccionarTodos = false;
            }
            const $filas = $tabla.find('tbody .js-chk-producto');
            $('#chk-seleccionar-todo').prop('checked', $filas.length > 0 && $filas.filter(':checked').length === $filas.length);
            actualizarBarraSeleccion();
        });

        $('#chk-seleccionar-todo').on('change', function () {
            const marcar = this.checked;
            $tabla.find('tbody .js-chk-producto').each(function () {
                $(this).prop('checked', marcar);
                const id = parseInt($(this).data('id'), 10);
                if (marcar) { selectedIds.add(id); } else { selectedIds.delete(id); }
            });
            if (!marcar) { seleccionarTodos = false; }
            actualizarBarraSeleccion();
        });

        $('#barra-seleccion-todos').on('click', function (e) {
            e.preventDefault();
            seleccionarTodos = true;
            actualizarBarraSeleccion();
        });

        $('#barra-seleccion-cerrar').on('click', function () {
            $tabla.find('tbody .js-chk-producto').prop('checked', false);
            limpiarSeleccion();
        });

        // --- Filtros (panel colapsable, aplican con "Buscar") ---
        $('#btn-aplicar-filtros').on('click', function () { tabla.ajax.reload(); });
        $('#filtro-estado, #filtro-tipo, #filtro-tipo-producto, #filtro-deposito, #filtro-proveedor').on('change', function () {
            tabla.ajax.reload();
        });
        $('#filtro-id, #filtro-buscar, #filtro-stock-min, #filtro-stock-max').on('keydown', function (e) {
            if (e.key === 'Enter') { tabla.ajax.reload(); }
        });
        $('#btn-limpiar-filtros').on('click', function () {
            $('#filtro-id, #filtro-buscar, #filtro-stock-min, #filtro-stock-max').val('');
            $('#filtro-deposito, #filtro-tipo, #filtro-tipo-producto, #filtro-proveedor').val('');
            $('#filtro-estado').val('activos');
            refreshSelect2($('#filtro-deposito'));
            refreshSelect2($('#filtro-estado'));
            refreshSelect2($('#filtro-tipo'));
            refreshSelect2($('#filtro-tipo-producto'));
            refreshSelect2($('#filtro-proveedor'));
            tabla.ajax.reload();
        });

        // Select2 en los filtros de datos dinámicos (Depósito, Tipo de Producto,
        // Proveedor) y en los selects de estado/tipo para una UX consistente.
        initSelect2($('#filtro-deposito'));
        initSelect2($('#filtro-tipo-producto'));
        initSelect2($('#filtro-proveedor'));
        initSelect2($('#filtro-estado'), { minimumResultsForSearch: Infinity });
        initSelect2($('#filtro-tipo'), { minimumResultsForSearch: Infinity });

        // --- Selector de columnas (colvis casero via API de DataTables) ---
        (function construirMenuColumnas() {
            const $menu = $('#menu-columnas');
            tabla.columns().every(function (idx) {
                const titulo = $(this.header()).text().trim();
                if (!titulo || idx === tabla.columns().count() - 1) { return; } // omitir Acciones
                const id = 'col-vis-' + idx;
                const $li = $(
                    '<li><label class="dropdown-item d-flex align-items-center gap-2 mb-0" for="' + id + '">' +
                    '<input type="checkbox" class="form-check-input mt-0" id="' + id + '" checked data-col="' + idx + '"> ' +
                    esc(titulo) + '</label></li>'
                );
                $menu.append($li);
            });
            $menu.on('change', 'input[data-col]', function () {
                const col = tabla.column($(this).data('col'));
                col.visible($(this).is(':checked'));
            });
        })();

        function refrescarStats() {
            if (!rutas.stats) {
                return;
            }
            $.getJSON(rutas.stats).done(function (s) {
                const fmt = function (n) { return new Intl.NumberFormat('es-AR').format(n); };
                const fmtMoney = function (n) { return new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(n); };
                $('#stat-unidades').text(fmt(s.unidades_en_stock));
                $('#stat-costo-total').text('$ ' + fmtMoney(s.costo_total));
                $('#stat-valor-venta-total').text('$ ' + fmtMoney(s.valor_venta_total));
            });
        }

        // Tooltips de la página (íconos "?" de los KPIs y el botón "Ver Totales").
        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new window.bootstrap.Tooltip(el);
            });
        }

        // --- "Ver Totales" (ícono de ojo, oculto por defecto — fiel a Contagram) ---
        const $panelTotales = $('#panel-totales');
        const $btnVerTotales = $('#btn-ver-totales');
        $btnVerTotales.on('click', function () {
            const abierto = $panelTotales.toggleClass('d-none').hasClass('d-none') === false;
            $(this).find('i').toggleClass('fa-eye-slash', !abierto).toggleClass('fa-eye', abierto);
            if (window.bootstrap && window.bootstrap.Tooltip) {
                const instancia = window.bootstrap.Tooltip.getInstance(this);
                if (instancia) {
                    instancia.dispose();
                }
                $(this).attr('title', abierto ? 'Ocultar Totales' : 'Ver Totales');
                new window.bootstrap.Tooltip(this);
            }
        });

        // Exportar respetando filtros y búsqueda.
        $('#btn-exportar').on('click', function () {
            const params = new URLSearchParams({
                estado: $('#filtro-estado').val() || '',
                tipo: $('#filtro-tipo').val() || '',
                buscar: (tabla.search() || ''),
            });
            window.location = rutas.export + '?' + params.toString();
            toast('info', 'Generando la exportación...');
        });

        // ================== MODAL DE ALTA / EDICIÓN ==================
        const $modal = $('#modal-producto');
        const modal = window.bootstrap ? new window.bootstrap.Modal($modal[0]) : null;
        const $form = $('#form-producto');

        initSelect2($form.find('select[name="stock_inicial_deposito_id"]'), { dropdownParent: $modal });
        initSelect2($('#producto-proveedor'), { dropdownParent: $modal, placeholder: 'Elija Proveedor' });

        // ---- Tipo de Producto (catálogo con Select2 + crear/renombrar/eliminar) ----
        let tiposProducto = (cfg.tiposProducto || []).slice();
        const $tipoProdSel = $('#producto-tipo-producto');
        let tipoProdPrevio = '';

        function actualizarBotonesTipo() {
            const val = $tipoProdSel.val();
            const real = !!val && val !== '__nuevo__';
            $('#btn-renombrar-tipo-producto, #btn-eliminar-tipo-producto').toggleClass('d-none', !real);
        }

        function renderTiposProducto(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $tipoProdSel.empty();
            $tipoProdSel.append(new Option('Elija el Tipo de Producto', '', false, !sel));
            $tipoProdSel.append(new Option('＋ Crear Tipo de Producto', '__nuevo__', false, false));
            tiposProducto.forEach(function (t) {
                $tipoProdSel.append(new Option(t.nombre, t.id, false, String(t.id) === sel));
            });
            refreshSelect2($tipoProdSel);
            tipoProdPrevio = sel;
            actualizarBotonesTipo();
        }

        initSelect2($tipoProdSel, { dropdownParent: $modal, placeholder: 'Elija el Tipo de Producto' });
        renderTiposProducto('');

        $tipoProdSel.on('change', function () {
            const val = $(this).val();
            if (val === '__nuevo__') {
                // Revertir la selección y abrir el modal de creación.
                $(this).val(tipoProdPrevio).trigger('change.select2');
                abrirModalTipoNombre('crear', '', '');
            } else {
                tipoProdPrevio = val || '';
                actualizarBotonesTipo();
            }
        });

        // Modal crear/renombrar tipo de producto.
        const $modalTipoNombre = $('#modal-tipo-nombre');
        const modalTipoNombre = window.bootstrap ? new window.bootstrap.Modal($modalTipoNombre[0]) : null;
        const $formTipoNombre = $('#form-tipo-nombre');

        function abrirModalTipoNombre(modo, id, nombreActual) {
            $('#tipo-nombre-id').val(id || '');
            $('#tipo-nombre-input').val(nombreActual || '').removeClass('is-invalid');
            $('#tipo-nombre-error').text('');
            $('#modal-tipo-nombre-titulo').text(modo === 'renombrar' ? 'Renombrar Tipo de Producto' : 'Nuevo Tipo de Producto');
            $('#btn-guardar-tipo-nombre').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            modalTipoNombre ? modalTipoNombre.show() : $modalTipoNombre.show();
            setTimeout(function () { $('#tipo-nombre-input').trigger('focus'); }, 300);
        }

        $formTipoNombre.on('submit', function (e) {
            e.preventDefault();
            const id = $('#tipo-nombre-id').val();
            const nombre = $('#tipo-nombre-input').val().trim();
            $('#tipo-nombre-input').removeClass('is-invalid');
            $('#tipo-nombre-error').text('');
            if (!nombre) {
                $('#tipo-nombre-input').addClass('is-invalid');
                $('#tipo-nombre-error').text('Ingresá un nombre.');
                return;
            }
            const esRen = !!id;
            const url = esRen ? rutas.tipos + '/' + id : rutas.tipos;
            const datos = esRen ? { _method: 'PATCH', nombre: nombre } : { nombre: nombre };
            $('#btn-guardar-tipo-nombre').prop('disabled', true);
            $.ajax({ url: url, method: 'POST', dataType: 'json', data: datos })
                .done(function (resp) {
                    if (esRen) {
                        const t = tiposProducto.find(function (x) { return String(x.id) === String(id); });
                        if (t) { t.nombre = resp.tipo.nombre; }
                        tiposProducto.sort(function (a, b) { return a.nombre.localeCompare(b.nombre); });
                        renderTiposProducto(id);
                    } else {
                        tiposProducto.push({ id: resp.tipo.id, nombre: resp.tipo.nombre });
                        tiposProducto.sort(function (a, b) { return a.nombre.localeCompare(b.nombre); });
                        renderTiposProducto(resp.tipo.id);
                    }
                    modalTipoNombre ? modalTipoNombre.hide() : $modalTipoNombre.hide();
                    toast('success', resp.mensaje);
                })
                .fail(function (xhr) {
                    const msg = (xhr.responseJSON && (xhr.responseJSON.message ||
                        (xhr.responseJSON.errors && xhr.responseJSON.errors.nombre && xhr.responseJSON.errors.nombre[0]))) ||
                        'No se pudo guardar el tipo.';
                    $('#tipo-nombre-input').addClass('is-invalid');
                    $('#tipo-nombre-error').text(msg);
                })
                .always(function () { $('#btn-guardar-tipo-nombre').prop('disabled', false); });
        });

        $('#btn-renombrar-tipo-producto').on('click', function (e) {
            e.preventDefault();
            const id = $tipoProdSel.val();
            if (!id || id === '__nuevo__') { return; }
            const t = tiposProducto.find(function (x) { return String(x.id) === String(id); });
            abrirModalTipoNombre('renombrar', id, t ? t.nombre : '');
        });

        // Eliminar tipo de producto (modal de confirmación).
        const $modalTipoEliminar = $('#modal-tipo-eliminar');
        const modalTipoEliminar = window.bootstrap ? new window.bootstrap.Modal($modalTipoEliminar[0]) : null;
        let idTipoAEliminar = null;

        $('#btn-eliminar-tipo-producto').on('click', function (e) {
            e.preventDefault();
            const id = $tipoProdSel.val();
            if (!id || id === '__nuevo__') { return; }
            const t = tiposProducto.find(function (x) { return String(x.id) === String(id); });
            idTipoAEliminar = id;
            $('#tipo-eliminar-nombre').text(t ? t.nombre : '');
            modalTipoEliminar ? modalTipoEliminar.show() : $modalTipoEliminar.show();
        });

        $('#btn-confirmar-eliminar-tipo').on('click', function () {
            if (!idTipoAEliminar) { return; }
            const id = idTipoAEliminar;
            $.ajax({ url: rutas.tipos + '/' + id, method: 'POST', dataType: 'json', data: { _method: 'DELETE' } })
                .done(function (resp) {
                    tiposProducto = tiposProducto.filter(function (x) { return String(x.id) !== String(id); });
                    renderTiposProducto('');
                    toast('success', resp.mensaje);
                })
                .fail(function () { toast('error', 'No se pudo eliminar el tipo.'); })
                .always(function () {
                    modalTipoEliminar ? modalTipoEliminar.hide() : $modalTipoEliminar.hide();
                    idTipoAEliminar = null;
                });
        });

        function limpiarErrores() {
            $form.find('.is-invalid').removeClass('is-invalid');
            $form.find('.invalid-feedback').text('');
        }

        function toggleStockSection() {
            // La sección de Variantes está oculta globalmente (Contagram no la expone).
            // Se fuerza el ocultado por si en el futuro se reactiva por tipo: un
            // servicio nunca controla stock ni variantes.
            $('#seccion-variantes').addClass('d-none');

            // Stock inicial: sólo al CREAR (sin id) un producto de Tipo=Producto.
            // Al editar, el stock ya cargado se ajusta desde "Ajuste de Stock".
            const esEdicion = !!$('#producto-id').val();
            const esProducto = $('#producto-tipo').val() === 'producto';
            $('#stock-inicial-wrap, #stock-inicial-deposito-wrap').toggleClass('d-none', esEdicion || !esProducto);
        }

        function resetForm() {
            $form[0].reset();
            $('#producto-id').val('');
            refreshSelect2($('#producto-proveedor'));
            $('#variantes-container').empty();
            $('#toggle-listas-precio').prop('checked', false);
            renderListasPrecio([]);
            toggleListasWrap();
            $('#producto-imagen-eliminar').val('0');
            $('#producto-imagen-preview-wrap').addClass('d-none');
            $('#producto-imagen-preview').attr('src', '');
            renderTiposProducto('');
            limpiarErrores();
            toggleStockSection();
        }

        function mostrarPreviewImagen(src) {
            if (src) {
                $('#producto-imagen-preview').attr('src', src);
                $('#producto-imagen-preview-wrap').removeClass('d-none');
            } else {
                $('#producto-imagen-preview-wrap').addClass('d-none');
                $('#producto-imagen-preview').attr('src', '');
            }
        }

        // Preview al elegir un archivo nuevo.
        $('#producto-imagen').on('change', function () {
            const file = this.files && this.files[0];
            if (file) {
                $('#producto-imagen-eliminar').val('0');
                mostrarPreviewImagen(URL.createObjectURL(file));
            }
        });

        // Quitar imagen existente.
        $('#btn-quitar-imagen').on('click', function () {
            $('#producto-imagen').val('');
            $('#producto-imagen-eliminar').val('1');
            mostrarPreviewImagen(null);
        });

        function abrirModal(titulo) {
            $('#modal-producto-titulo').text(titulo);
            modal ? modal.show() : $modal.show();
        }

        function cerrarModal() {
            modal ? modal.hide() : $modal.hide();
        }

        $('#producto-tipo').on('change', toggleStockSection);

        $('#btn-nuevo-producto').on('click', function () {
            resetForm();
            abrirModal('Nuevo Producto');
        });

        // --- Variantes dinámicas (US4) ---
        let varianteIdx = 0;
        function agregarVariante(item) {
            item = item || {};
            const idx = varianteIdx++;
            const $row = $($('#tpl-variante')[0].content.cloneNode(true)).children();
            $row.find('[data-name]').each(function () {
                const campo = $(this).data('name');
                $(this).attr('name', 'variantes[' + idx + '][' + campo + ']');
                if (item[campo] !== undefined && item[campo] !== null) {
                    $(this).val(item[campo]);
                }
            });
            $('#variantes-container').append($row);
        }

        $('#btn-agregar-variante').on('click', function () {
            agregarVariante({});
        });

        // --- Listas de precio (US5): catálogo global gestionable inline ---
        let listasPrecio = (cfg.listasPrecio || []).slice();

        function renderListasPrecio(precios) {
            const preciosPorLista = {};
            (precios || []).forEach(function (pr) { preciosPorLista[pr.lista_precio_id] = pr.precio; });

            const $cont = $('#listas-precio-container').empty();
            listasPrecio.forEach(function (lista, i) {
                const valor = preciosPorLista[lista.id] !== undefined ? preciosPorLista[lista.id] : '';
                const $col = $(
                    '<div class="col-lg-4 js-lista-item" data-lista="' + lista.id + '">' +
                        '<label class="form-label d-flex align-items-center gap-1 mb-1">' +
                            '<span class="js-lista-nombre flex-grow-1">' + esc(lista.nombre) + '</span>' +
                            '<a href="#" class="js-lista-renombrar text-primary" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>' +
                            '<a href="#" class="js-lista-eliminar text-danger" title="Eliminar"><i class="fas fa-trash-alt"></i></a>' +
                        '</label>' +
                        '<input type="hidden" name="precios[' + i + '][lista_precio_id]" value="' + lista.id + '">' +
                        '<input type="number" step="0.01" min="0" class="form-control js-precio-lista" ' +
                            'name="precios[' + i + '][precio]" data-lista="' + lista.id + '" placeholder="0">' +
                    '</div>'
                );
                $col.find('.js-precio-lista').val(valor);
                $cont.append($col);
            });
        }

        function toggleListasWrap() {
            const on = $('#toggle-listas-precio').is(':checked');
            $('#listas-precio-wrap').toggleClass('d-none', !on);
        }

        $('#toggle-listas-precio').on('change', toggleListasWrap);

        // Modal de nombre de lista (crear/renombrar) — reemplaza el prompt nativo.
        const $modalListaNombre = $('#modal-lista-nombre');
        const modalListaNombre = window.bootstrap ? new window.bootstrap.Modal($modalListaNombre[0]) : null;
        const $formListaNombre = $('#form-lista-nombre');

        function abrirModalListaNombre(modo, id, nombreActual) {
            $('#lista-nombre-id').val(id || '');
            $('#lista-nombre-input').val(nombreActual || '').removeClass('is-invalid');
            $('#lista-nombre-error').text('');
            $('#modal-lista-nombre-titulo').text(modo === 'renombrar' ? 'Renombrar Lista de Precios' : 'Nueva Lista de Precios');
            modalListaNombre ? modalListaNombre.show() : $modalListaNombre.show();
            setTimeout(function () { $('#lista-nombre-input').trigger('focus'); }, 300);
        }

        $('#btn-agregar-lista-precio').on('click', function () {
            abrirModalListaNombre('crear', '', '');
        });

        $formListaNombre.on('submit', function (e) {
            e.preventDefault();
            const id = $('#lista-nombre-id').val();
            const nombre = $('#lista-nombre-input').val().trim();
            $('#lista-nombre-input').removeClass('is-invalid');
            $('#lista-nombre-error').text('');
            if (!nombre) {
                $('#lista-nombre-input').addClass('is-invalid');
                $('#lista-nombre-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = !!id;
            const url = esRenombrar ? rutas.listas + '/' + id : rutas.listas;
            const datos = esRenombrar ? { _method: 'PATCH', nombre: nombre } : { nombre: nombre };

            $('#btn-guardar-lista-nombre').prop('disabled', true);
            $.ajax({ url: url, method: 'POST', dataType: 'json', data: datos })
                .done(function (resp) {
                    if (esRenombrar) {
                        const lista = listasPrecio.find(function (l) { return String(l.id) === String(id); });
                        if (lista) { lista.nombre = resp.lista.nombre; }
                        $('#listas-precio-container .js-lista-item[data-lista="' + id + '"] .js-lista-nombre').text(resp.lista.nombre);
                    } else {
                        listasPrecio.push({ id: resp.lista.id, nombre: resp.lista.nombre });
                        renderListasPrecio(recolectarPreciosActuales());
                    }
                    modalListaNombre ? modalListaNombre.hide() : $modalListaNombre.hide();
                    toast('success', resp.mensaje);
                })
                .fail(function (xhr) {
                    const msg = (xhr.responseJSON && (xhr.responseJSON.message ||
                        (xhr.responseJSON.errors && xhr.responseJSON.errors.nombre && xhr.responseJSON.errors.nombre[0]))) ||
                        'No se pudo guardar la lista.';
                    $('#lista-nombre-input').addClass('is-invalid');
                    $('#lista-nombre-error').text(msg);
                })
                .always(function () { $('#btn-guardar-lista-nombre').prop('disabled', false); });
        });

        // Recolecta los precios cargados en el form para no perderlos al re-render.
        function recolectarPreciosActuales() {
            const out = [];
            $('#listas-precio-container .js-precio-lista').each(function () {
                const v = $(this).val();
                if (v !== '') { out.push({ lista_precio_id: $(this).data('lista'), precio: v }); }
            });
            return out;
        }

        $('#listas-precio-container').on('click', '.js-lista-renombrar', function (e) {
            e.preventDefault();
            const $item = $(this).closest('.js-lista-item');
            abrirModalListaNombre('renombrar', $item.data('lista'), $item.find('.js-lista-nombre').text());
        });

        // Eliminar lista vía modal de confirmación.
        const $modalListaEliminar = $('#modal-lista-eliminar');
        const modalListaEliminar = window.bootstrap ? new window.bootstrap.Modal($modalListaEliminar[0]) : null;
        let idListaAEliminar = null;

        $('#listas-precio-container').on('click', '.js-lista-eliminar', function (e) {
            e.preventDefault();
            const $item = $(this).closest('.js-lista-item');
            idListaAEliminar = $item.data('lista');
            $('#lista-eliminar-nombre').text($item.find('.js-lista-nombre').text());
            modalListaEliminar ? modalListaEliminar.show() : $modalListaEliminar.show();
        });

        $('#btn-confirmar-eliminar-lista').on('click', function () {
            if (!idListaAEliminar) { return; }
            const id = idListaAEliminar;
            $.ajax({
                url: rutas.listas + '/' + id, method: 'POST', dataType: 'json',
                data: { _method: 'DELETE' },
            }).done(function (resp) {
                listasPrecio = listasPrecio.filter(function (l) { return String(l.id) !== String(id); });
                renderListasPrecio(recolectarPreciosActuales());
                toast('success', resp.mensaje);
            }).fail(function () {
                toast('error', 'No se pudo eliminar la lista.');
            }).always(function () {
                modalListaEliminar ? modalListaEliminar.hide() : $modalListaEliminar.hide();
                idListaAEliminar = null;
            });
        });

        $('#variantes-container').on('click', '.js-quitar-variante', function () {
            $(this).closest('.js-variante').remove();
        });

        // Editar / Ver: precargar por AJAX.
        $tabla.on('click', '.js-producto-editar, .js-producto-ver', function (e) {
            e.preventDefault();
            const soloVer = $(this).hasClass('js-producto-ver');
            const id = $(this).data('id');
            resetForm();
            $.getJSON(rutas.show + '/' + id)
                .done(function (resp) {
                    const p = resp.producto;
                    $('#producto-id').val(p.id);
                    const complejos = ['variantes', 'precios', 'imagen', 'imagen_url'];
                    Object.keys(p).forEach(function (campo) {
                        if (complejos.indexOf(campo) !== -1) {
                            return;
                        }
                        const $input = $form.find('[name="' + campo + '"]');
                        if (!$input.length || $input.attr('type') === 'file') {
                            return;
                        }
                        // Un campo puede tener varios controles con el mismo name
                        // (ej. hidden "0" + checkbox "1"). Priorizar checkbox/radio y
                        // NO pisar el value del hidden que los acompaña.
                        const $checkbox = $input.filter(':checkbox');
                        const $radios = $input.filter(':radio');
                        if ($checkbox.length) {
                            $checkbox.prop('checked', !!p[campo] && p[campo] != 0);
                        } else if ($radios.length) {
                            // Grupo de radios (ej. Estado activo/inactivo): marcar el
                            // que coincida con el valor normalizado a '1'/'0'.
                            const valor = (p[campo] === null || p[campo] === undefined) ? '' : String(p[campo] ? 1 : 0);
                            $radios.filter('[value="' + valor + '"]').prop('checked', true);
                        } else {
                            $input.val(p[campo] === null ? '' : p[campo]);
                        }
                    });
                    refreshSelect2($('#producto-proveedor'));
                    (p.variantes || []).forEach(function (v) { agregarVariante(v); });
                    renderListasPrecio(p.precios || []);
                    $('#toggle-listas-precio').prop('checked', (p.precios || []).length > 0);
                    toggleListasWrap();
                    renderTiposProducto(p.tipo_producto_id || '');
                    mostrarPreviewImagen(p.imagen_url);
                    toggleStockSection();
                    abrirModal(soloVer ? 'Ver Producto' : 'Editar Producto');
                })
                .fail(function () {
                    toast('error', 'No se pudo cargar el producto.');
                });
        });

        // Crear Copia.
        $tabla.on('click', '.js-producto-copia', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            $.ajax({ url: rutas.show + '/' + id + '/copia', method: 'POST', dataType: 'json' })
                .done(function (resp) {
                    tabla.ajax.reload(null, false);
                    refrescarStats();
                    toast('success', resp.mensaje || 'Producto copiado.');
                })
                .fail(function () { toast('error', 'No se pudo copiar el producto.'); });
        });

        // Aumentar / Disminuir stock desde la fila (abre el modal global preseleccionado).
        $tabla.on('click', '.js-producto-aumentar', function (e) {
            e.preventDefault();
            if (window.ProductosStockOp) { window.ProductosStockOp('aumento', $(this).data('id')); }
        });
        $tabla.on('click', '.js-producto-disminuir', function (e) {
            e.preventDefault();
            if (window.ProductosStockOp) { window.ProductosStockOp('disminucion', $(this).data('id')); }
        });

        function mostrarErrores(errors) {
            Object.keys(errors).forEach(function (campo) {
                const base = campo.split('.')[0];
                const $input = $form.find('[name="' + campo + '"], [name="' + base + '"]');
                $input.addClass('is-invalid');
                const $fb = $form.find('.invalid-feedback[data-field="' + base + '"]');
                if ($fb.length) {
                    $fb.text(errors[campo][0]);
                }
            });
        }

        // Submit (store/update).
        $form.on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const id = $('#producto-id').val();
            const esEdicion = !!id;
            const url = esEdicion ? rutas.show + '/' + id : rutas.store;
            const datos = new FormData($form[0]);
            if (esEdicion) {
                datos.append('_method', 'PATCH');
            }

            $('#btn-guardar-producto').prop('disabled', true);

            $.ajax({
                url: url,
                method: 'POST',
                data: datos,
                processData: false,
                contentType: false,
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .done(function (resp) {
                    cerrarModal();
                    tabla.ajax.reload(null, false);
                    refrescarStats();
                    toast('success', resp.mensaje || 'Guardado.');
                })
                .fail(function (xhr) {
                    if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                        mostrarErrores(xhr.responseJSON.errors);
                        toast('error', 'Revisá los datos del formulario.');
                    } else {
                        toast('error', 'No se pudo guardar el producto.');
                    }
                })
                .always(function () {
                    $('#btn-guardar-producto').prop('disabled', false);
                });
        });

        // ================== ESTADO: inactivar / reactivar (US8) ==================
        $tabla.on('click', '.js-producto-estado', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            $.ajax({
                url: rutas.show + '/' + id + '/estado',
                method: 'POST',
                data: { _method: 'PATCH' },
                dataType: 'json',
            })
                .done(function (resp) {
                    tabla.ajax.reload(null, false);
                    refrescarStats();
                    toast('success', resp.mensaje);
                })
                .fail(function () { toast('error', 'No se pudo cambiar el estado.'); });
        });

        // ================== ELIMINAR (US8) ==================
        const $modalEliminar = $('#modal-eliminar-producto');
        const modalEliminar = window.bootstrap ? new window.bootstrap.Modal($modalEliminar[0]) : null;
        let idAEliminar = null;

        $tabla.on('click', '.js-producto-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            modalEliminar ? modalEliminar.show() : $modalEliminar.show();
        });

        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) {
                return;
            }
            $.ajax({
                url: rutas.show + '/' + idAEliminar,
                method: 'POST',
                data: { _method: 'DELETE' },
                dataType: 'json',
            })
                .done(function (resp) {
                    tabla.ajax.reload(null, false);
                    refrescarStats();
                    toast('success', resp.mensaje);
                })
                .fail(function (xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || 'No se pudo eliminar el producto.';
                    toast('error', msg);
                })
                .always(function () {
                    modalEliminar ? modalEliminar.hide() : $modalEliminar.hide();
                    idAEliminar = null;
                });
        });

        // ================== AJUSTE DE STOCK (US6) ==================
        const $modalStock = $('#modal-stock');
        const modalStock = window.bootstrap ? new window.bootstrap.Modal($modalStock[0]) : null;
        const $formStock = $('#form-stock');

        // Depósito y variante del modal de ajuste per-producto con Select2.
        initSelect2($('#stock-deposito'), { dropdownParent: $modalStock });
        initSelect2($('#stock-variante'), { dropdownParent: $modalStock });

        $tabla.on('click', '.js-producto-stock', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            $formStock[0].reset();
            $formStock.find('.is-invalid').removeClass('is-invalid');
            $('#stock-producto-id').val(id);

            $.getJSON(rutas.show + '/' + id).done(function (resp) {
                const p = resp.producto;
                $('#stock-producto-nombre').text(p.nombre);

                const $wrap = $('#stock-variante-wrap');
                const $sel = $('#stock-variante').empty();
                if (p.variantes && p.variantes.length) {
                    p.variantes.forEach(function (v) {
                        const etiqueta = v.sku || v.nombre || ([v.talle, v.color].filter(Boolean).join(' ')) || ('Variante #' + v.id);
                        $sel.append('<option value="' + v.id + '">' + esc(etiqueta) + '</option>');
                    });
                    $wrap.removeClass('d-none');
                } else {
                    $wrap.addClass('d-none');
                }
                refreshSelect2($sel);
                refreshSelect2($('#stock-deposito'));

                modalStock ? modalStock.show() : $modalStock.show();
            }).fail(function () {
                toast('error', 'No se pudo cargar el producto.');
            });
        });

        $formStock.on('submit', function (e) {
            e.preventDefault();
            const id = $('#stock-producto-id').val();
            $formStock.find('.is-invalid').removeClass('is-invalid');
            $('#btn-guardar-stock').prop('disabled', true);

            $.ajax({
                url: rutas.show + '/' + id + '/stock',
                method: 'POST',
                data: $formStock.serialize(),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .done(function (resp) {
                    toast('success', resp.mensaje + ' Stock actual: ' + new Intl.NumberFormat('es-AR').format(resp.stock_actual));
                    tabla.ajax.reload(null, false);
                    refrescarStats();
                })
                .fail(function (xhr) {
                    if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                        const errors = xhr.responseJSON.errors;
                        Object.keys(errors).forEach(function (campo) {
                            $formStock.find('[name="' + campo + '"]').addClass('is-invalid');
                            $formStock.find('.invalid-feedback[data-field="' + campo + '"]').text(errors[campo][0]);
                        });
                        toast('error', errors.producto ? errors.producto[0] : 'Revisá los datos del ajuste.');
                    } else {
                        toast('error', 'No se pudo registrar el ajuste.');
                    }
                })
                .always(function () {
                    $('#btn-guardar-stock').prop('disabled', false);
                });
        });

        // ============= AJUSTE DE STOCK GLOBAL (Aumento / Disminución / Transferencia) =============
        const $modalOp = $('#modal-stock-op');
        const modalOp = window.bootstrap ? new window.bootstrap.Modal($modalOp[0]) : null;
        const $formOp = $('#form-stock-op');
        const TITULOS = {
            aumento: 'Nuevo Aumento',
            disminucion: 'Nueva Disminución',
            transferencia: 'Nuevo Movimiento entre Depósitos',
        };

        function etiquetaProducto(p) {
            return p.codigo ? (p.nombre + ' (' + p.codigo + ')') : p.nombre;
        }

        // Picker de producto con Select2 + búsqueda remota (AJAX contra rutas.opciones).
        initSelect2($('#stock-op-producto'), {
            dropdownParent: $modalOp,
            placeholder: 'Buscar por nombre o código…',
            minimumInputLength: 0,
            ajax: {
                url: rutas.opciones,
                dataType: 'json',
                delay: 250,
                data: function (params) { return { q: params.term || '' }; },
                processResults: function (data) {
                    return { results: (data.data || []).map(function (p) { return { id: p.id, text: etiquetaProducto(p) }; }) };
                },
            },
        });

        function cargarVarianteOp() {
            const id = $('#stock-op-producto').val();
            const $wrap = $('#stock-op-variante-wrap');
            const $sel = $('#stock-op-variante').empty();
            if (!id) { $wrap.addClass('d-none'); return; }
            $.getJSON(rutas.show + '/' + id).done(function (resp) {
                const vs = (resp.producto && resp.producto.variantes) || [];
                if (vs.length) {
                    vs.forEach(function (v) {
                        const etiqueta = v.sku || v.nombre || ([v.talle, v.color].filter(Boolean).join(' ')) || ('Variante #' + v.id);
                        $sel.append($('<option>').val(v.id).text(etiqueta));
                    });
                    $wrap.removeClass('d-none');
                } else {
                    $wrap.addClass('d-none');
                }
                refreshSelect2($sel);
            });
        }

        // Depósitos y variante del modal de stock con Select2 (buscador).
        initSelect2($('#stock-op-deposito'), { dropdownParent: $modalOp });
        initSelect2($('#stock-op-entrada'), { dropdownParent: $modalOp });
        initSelect2($('#stock-op-variante'), { dropdownParent: $modalOp });

        $('#stock-op-producto').on('change', cargarVarianteOp);

        function abrirOp(tipo, productoId) {
            $formOp[0].reset();
            $formOp.find('.is-invalid').removeClass('is-invalid');
            $formOp.find('.invalid-feedback').text('');
            $('#stock-op-tipo').val(tipo);
            $('#modal-stock-op-titulo').text(TITULOS[tipo] || 'Ajuste de Stock');

            const esTransf = tipo === 'transferencia';
            $('#stock-op-entrada-wrap').toggleClass('d-none', !esTransf);
            $('#stock-op-deposito-label').text(esTransf ? 'Depósito de Salida' : 'Depósito');
            $('#stock-op-nota-label').text(esTransf ? 'Observación' : 'Nota interna');

            // Limpiar el picker de producto (Select2 AJAX) y variantes.
            $('#stock-op-producto').val(null).trigger('change');
            $('#stock-op-variante-wrap').addClass('d-none');
            refreshSelect2($('#stock-op-deposito'));
            refreshSelect2($('#stock-op-entrada'));

            if (productoId) {
                // Preseleccionar (viene desde la acción de fila): crear la opción
                // en el Select2 AJAX y marcarla seleccionada.
                $.getJSON(rutas.show + '/' + productoId).done(function (resp) {
                    const p = resp.producto;
                    const opt = new Option(etiquetaProducto(p), p.id, true, true);
                    $('#stock-op-producto').append(opt).trigger('change');
                    cargarVarianteOp();
                });
            }
            modalOp ? modalOp.show() : $modalOp.show();
        }

        $('.js-stock-op').on('click', function (e) {
            e.preventDefault();
            abrirOp($(this).data('tipo'), null);
        });

        $formOp.on('submit', function (e) {
            e.preventDefault();
            $formOp.find('.is-invalid').removeClass('is-invalid');
            $formOp.find('.invalid-feedback').text('');

            const tipo = $('#stock-op-tipo').val();
            const productoId = $('#stock-op-producto').val();
            if (!productoId) {
                $('#stock-op-producto').addClass('is-invalid');
                toast('error', 'Elegí un producto.');
                return;
            }

            const esTransf = tipo === 'transferencia';
            const url = rutas.show + '/' + productoId + (esTransf ? '/transferencia' : '/stock');
            const datos = {
                fecha: $formOp.find('[name="fecha"]').val(),
                cantidad: $formOp.find('[name="cantidad"]').val(),
                descripcion: $formOp.find('[name="descripcion"]').val(),
                variante_id: $formOp.find('[name="variante_id"]').val(),
            };
            if (esTransf) {
                datos.deposito_salida_id = $formOp.find('[name="deposito_id"]').val();
                datos.deposito_entrada_id = $formOp.find('[name="deposito_entrada_id"]').val();
            } else {
                datos.operacion = tipo;
                datos.deposito_id = $formOp.find('[name="deposito_id"]').val();
            }

            $('#btn-guardar-stock-op').prop('disabled', true);
            $.ajax({
                url: url,
                method: 'POST',
                data: datos,
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .done(function (resp) {
                    modalOp ? modalOp.hide() : $modalOp.hide();
                    tabla.ajax.reload(null, false);
                    refrescarStats();
                    toast('success', resp.mensaje || 'Operación registrada.');
                })
                .fail(function (xhr) {
                    if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                        const errors = xhr.responseJSON.errors;
                        Object.keys(errors).forEach(function (campo) {
                            const base = campo.split('.')[0];
                            $formOp.find('[name="' + base + '"]').addClass('is-invalid');
                            $formOp.find('.invalid-feedback[data-field="' + base + '"]').text(errors[campo][0]);
                        });
                        toast('error', errors.producto ? errors.producto[0] : 'Revisá los datos.');
                    } else {
                        toast('error', 'No se pudo registrar la operación.');
                    }
                })
                .always(function () {
                    $('#btn-guardar-stock-op').prop('disabled', false);
                });
        });

        // Exponer para las acciones de fila (Aumentar/Disminuir desde el dropdown).
        window.ProductosStockOp = abrirOp;

        // ================== ACCIONES MASIVAS (004) ==================
        // 4 acciones (Precio de Venta, Costo, IVA por defecto, Tipo de Producto) tienen modal
        // propio en Contagram real (capturas/acciones masivas) — el resto usa este modal
        // genérico de "Elegí una Acción" + un único valor, tal cual ya validado.
        const $modalMasivas = $('#modal-acciones-masivas');
        const modalMasivas = window.bootstrap ? new window.bootstrap.Modal($modalMasivas[0]) : null;
        const $formMasivas = $('#form-acciones-masivas');
        const $accionSel = $('#masiva-accion');

        const ACCIONES_CON_MODAL_PROPIO = ['precio_venta', 'costo', 'iva', 'tipo_producto_id'];
        // Acciones del modal genérico que muestran un control de valor.
        const ACCIONES_CON_VALOR = ['activo', 'proveedor_id'];

        function renderSelectOpciones($sel, opciones, placeholder) {
            $sel.empty();
            $sel.append(new Option(placeholder, '', false, false));
            (opciones || []).forEach(function (o) { $sel.append(new Option(o.nombre, o.id, false, false)); });
        }
        renderSelectOpciones($('#masiva-proveedor'), cfg.proveedores, 'Elija Proveedor');
        initSelect2($('#masiva-proveedor'), { dropdownParent: $modalMasivas });

        function cantidadSeleccionada() {
            return seleccionarTodos ? totalFiltrado() : selectedIds.size;
        }

        function payloadSeleccion() {
            return seleccionarTodos
                ? { todos: 1, filtros: filtrosActuales() }
                : { todos: 0, ids: Array.from(selectedIds) };
        }

        function ejecutarAccionMasiva(payload) {
            return $.ajax({
                url: rutas.accionesMasivas,
                method: 'POST',
                data: payload,
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
        }

        function avisarResultadoAccionMasiva(resp, accion) {
            tabla.ajax.reload(null, false);
            refrescarStats();
            if (accion === 'eliminar') {
                const noEliminados = resp.no_eliminados || [];
                let msg = resp.eliminados + ' producto(s) eliminado(s).';
                if (noEliminados.length) {
                    msg += ' ' + noEliminados.length + ' no se eliminaron (tienen operaciones asociadas): ' +
                        noEliminados.map(function (p) { return p.nombre; }).join(', ') + '.';
                    toast('warning', msg);
                } else {
                    toast('success', msg);
                }
            } else {
                toast('success', resp.mensaje || 'Acción aplicada.');
            }
        }

        $accionSel.on('change', function () {
            const accion = $(this).val();

            if (ACCIONES_CON_MODAL_PROPIO.indexOf(accion) !== -1) {
                $(this).val('');
                $modalMasivas.one('hidden.bs.modal', function () { abrirModalMasivaPropia(accion); });
                modalMasivas ? modalMasivas.hide() : $modalMasivas.hide();
                return;
            }

            $formMasivas.find('[data-valor]').addClass('d-none');
            $('#masiva-eliminar-aviso').toggleClass('d-none', accion !== 'eliminar');
            if (ACCIONES_CON_VALOR.indexOf(accion) !== -1) {
                $formMasivas.find('[data-valor="' + accion + '"]').removeClass('d-none');
            }
            $('#btn-confirmar-acciones-masivas').prop('disabled', !accion);
        });

        function abrirModalMasivaPropia(accion) {
            if (accion === 'precio_venta' || accion === 'costo') { abrirModalMasivaPrecios(accion); }
            else if (accion === 'iva') { abrirModalMasivaIva(); }
            else if (accion === 'tipo_producto_id') { abrirModalMasivaTipoProducto(); }
        }

        function limpiarErroresMasivas() {
            $formMasivas.find('.is-invalid').removeClass('is-invalid');
            $formMasivas.find('.invalid-feedback').text('');
            $('#masiva-resultado-detalle').addClass('d-none').empty();
        }

        function abrirModalAccionesMasivas() {
            $formMasivas[0].reset();
            limpiarErroresMasivas();
            $formMasivas.find('[data-valor]').addClass('d-none');
            $('#masiva-eliminar-aviso').addClass('d-none');
            $('#btn-confirmar-acciones-masivas').prop('disabled', true);
            refreshSelect2($('#masiva-proveedor'));
            modalMasivas ? modalMasivas.show() : $modalMasivas.show();
        }

        $('#barra-seleccion-acciones').on('click', function (e) {
            e.preventDefault();
            if (!seleccionarTodos && selectedIds.size === 0) {
                return;
            }
            abrirModalAccionesMasivas();
        });

        function valorAccionMasiva(accion) {
            switch (accion) {
            case 'activo': return $formMasivas.find('[name="valor_activo"]').val();
            case 'proveedor_id': return $('#masiva-proveedor').val();
            default: return null;
            }
        }

        $formMasivas.on('submit', function (e) {
            e.preventDefault();
            limpiarErroresMasivas();

            const accion = $accionSel.val();
            if (!accion) {
                $accionSel.addClass('is-invalid');
                $formMasivas.find('.invalid-feedback[data-field="accion"]').text('Elegí una acción.');
                return;
            }

            const payload = Object.assign({ accion: accion, valor: valorAccionMasiva(accion) }, payloadSeleccion());

            $('#btn-confirmar-acciones-masivas').prop('disabled', true);
            ejecutarAccionMasiva(payload)
                .done(function (resp) {
                    modalMasivas ? modalMasivas.hide() : $modalMasivas.hide();
                    avisarResultadoAccionMasiva(resp, accion);
                })
                .fail(function (xhr) {
                    if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                        const errors = xhr.responseJSON.errors;
                        const mensaje = errors.accion ? errors.accion[0] : (errors.valor ? errors.valor[0] : 'Revisá los datos.');
                        if (errors.accion) {
                            $accionSel.addClass('is-invalid');
                            $formMasivas.find('.invalid-feedback[data-field="accion"]').text(errors.accion[0]);
                        }
                        if (errors.valor) {
                            $formMasivas.find('.invalid-feedback[data-field="valor"]').text(errors.valor[0]);
                        }
                        toast('error', mensaje);
                    } else {
                        toast('error', 'No se pudo aplicar la acción.');
                    }
                })
                .always(function () {
                    $('#btn-confirmar-acciones-masivas').prop('disabled', false);
                });
        });

        // ---- Edición Masiva de Precios de Venta / Costos ----
        const $modalPrecios = $('#modal-masiva-precios');
        const modalPrecios = window.bootstrap ? new window.bootstrap.Modal($modalPrecios[0]) : null;
        const $formPrecios = $('#form-masiva-precios');
        let modoPrecios = 'porcentaje';

        function filaCampoPrecio(campo, etiqueta) {
            const prefijo = modoPrecios === 'porcentaje' ? '%' : '$';
            return $(
                '<div class="row align-items-center mb-3" data-campo="' + campo + '">' +
                    '<div class="col-4"><label class="form-label mb-0">' + esc(etiqueta) + '</label></div>' +
                    '<div class="col-4"><div class="input-group">' +
                        '<span class="input-group-text js-prefijo">' + prefijo + '</span>' +
                        '<input type="number" step="0.01" class="form-control js-valor">' +
                    '</div></div>' +
                    '<div class="col-4 d-flex gap-3">' +
                        '<div class="form-check"><input class="form-check-input" type="radio" name="signo-' + campo + '" value="aumentar" checked>' +
                            '<label class="form-check-label">Aumentar</label></div>' +
                        '<div class="form-check"><input class="form-check-input" type="radio" name="signo-' + campo + '" value="disminuir">' +
                            '<label class="form-check-label">Disminuir</label></div>' +
                    '</div>' +
                '</div>'
            );
        }

        function actualizarModoPrecios(modo) {
            modoPrecios = modo;
            $formPrecios.find('.js-modo-precios').each(function () {
                const activo = $(this).data('modo') === modo;
                $(this).toggleClass('btn-primary', activo).toggleClass('btn-outline-primary', !activo);
            });
            $formPrecios.find('.js-prefijo').text(modo === 'porcentaje' ? '%' : '$');
        }

        $formPrecios.on('click', '.js-modo-precios', function () {
            actualizarModoPrecios($(this).data('modo'));
        });

        function abrirModalMasivaPrecios(accion) {
            const esCosto = accion === 'costo';
            $('#masiva-precios-accion').val(accion);
            $('#masiva-precios-titulo').text(esCosto ? 'Edición Masiva de Costos' : 'Edición Masiva de Precios de Venta');
            $('#masiva-precios-cantidad').text(cantidadSeleccionada());
            $('#masiva-precios-redondear-label').text('Redondear ' + (esCosto ? 'los costos' : 'los precios') + ' modificados al primer entero');
            $('#btn-actualizar-precios').text(esCosto ? 'Actualizar Costos' : 'Actualizar Precios');
            $('#masiva-precios-redondear').prop('checked', false);

            const $cont = $('#masiva-precios-campos').empty();
            if (esCosto) {
                $cont.append(filaCampoPrecio('costo', 'Costo'));
            } else {
                $cont.append(filaCampoPrecio('precio_venta', 'Precio de Venta'));
                (cfg.listasPrecio || []).forEach(function (lista) {
                    $cont.append(filaCampoPrecio('lista_' + lista.id, lista.nombre));
                });
            }
            actualizarModoPrecios('porcentaje');
            modalPrecios ? modalPrecios.show() : $modalPrecios.show();
        }

        $formPrecios.on('submit', function (e) {
            e.preventDefault();
            const accion = $('#masiva-precios-accion').val();
            const campos = {};
            $('#masiva-precios-campos [data-campo]').each(function () {
                const campo = $(this).data('campo');
                const valor = $(this).find('.js-valor').val();
                if (valor === '' || valor === null) { return; }
                const signo = $(this).find('input[type=radio]:checked').val();
                campos[campo] = { valor: valor, signo: signo };
            });
            if (! Object.keys(campos).length) {
                toast('error', 'Ingresá al menos un valor.');
                return;
            }

            const payload = Object.assign({
                accion: accion,
                modo: modoPrecios,
                redondear: $('#masiva-precios-redondear').is(':checked') ? 1 : 0,
                campos: campos,
            }, payloadSeleccion());

            $('#btn-actualizar-precios').prop('disabled', true);
            ejecutarAccionMasiva(payload)
                .done(function (resp) {
                    modalPrecios ? modalPrecios.hide() : $modalPrecios.hide();
                    avisarResultadoAccionMasiva(resp, accion);
                })
                .fail(function (xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.errors && Object.values(xhr.responseJSON.errors)[0][0])
                        || 'No se pudo aplicar la acción.';
                    toast('error', msg);
                })
                .always(function () {
                    $('#btn-actualizar-precios').prop('disabled', false);
                });
        });

        // ---- Edición IVA por Defecto ----
        const $modalIva = $('#modal-masiva-iva');
        const modalIva = window.bootstrap ? new window.bootstrap.Modal($modalIva[0]) : null;
        const $formIva = $('#form-masiva-iva');

        function abrirModalMasivaIva() {
            $formIva[0].reset();
            modalIva ? modalIva.show() : $modalIva.show();
        }

        $formIva.on('submit', function (e) {
            e.preventDefault();
            const payload = Object.assign({
                accion: 'iva',
                valor_venta: $('#masiva-iva-venta').val(),
                valor_compra: $('#masiva-iva-compra').val(),
            }, payloadSeleccion());

            const $btn = $formIva.find('button[type="submit"]').prop('disabled', true);
            ejecutarAccionMasiva(payload)
                .done(function (resp) {
                    modalIva ? modalIva.hide() : $modalIva.hide();
                    avisarResultadoAccionMasiva(resp, 'iva');
                })
                .fail(function () { toast('error', 'No se pudo aplicar la acción.'); })
                .always(function () { $btn.prop('disabled', false); });
        });

        // ---- Modificar Tipo de Producto ----
        const $modalTipoProd = $('#modal-masiva-tipo-producto');
        const modalTipoProd = window.bootstrap ? new window.bootstrap.Modal($modalTipoProd[0]) : null;
        const $formTipoProd = $('#form-masiva-tipo-producto');

        renderSelectOpciones($('#masiva-tipo-producto-producto'), cfg.tiposProducto, 'Elegí el Tipo de Producto');
        renderSelectOpciones($('#masiva-tipo-producto-servicio'), cfg.tiposProducto, 'Elegí el Tipo de Servicio');
        initSelect2($('#masiva-tipo-producto-producto'), { dropdownParent: $modalTipoProd });
        initSelect2($('#masiva-tipo-producto-servicio'), { dropdownParent: $modalTipoProd });

        function abrirModalMasivaTipoProducto() {
            $formTipoProd[0].reset();
            $('#masiva-tipo-producto-cantidad').text(cantidadSeleccionada());
            $('#masiva-tipo-producto-producto, #masiva-tipo-producto-servicio').val('');
            refreshSelect2($('#masiva-tipo-producto-producto'));
            refreshSelect2($('#masiva-tipo-producto-servicio'));
            modalTipoProd ? modalTipoProd.show() : $modalTipoProd.show();
        }

        $formTipoProd.on('submit', function (e) {
            e.preventDefault();
            const valorProducto = $('#masiva-tipo-producto-producto').val();
            const valorServicio = $('#masiva-tipo-producto-servicio').val();
            if (! valorProducto && ! valorServicio) {
                toast('error', 'Elegí al menos un Tipo (Producto o Servicio).');
                return;
            }

            const payload = Object.assign({
                accion: 'tipo_producto_id',
                valor_producto: valorProducto,
                valor_servicio: valorServicio,
            }, payloadSeleccion());

            const $btn = $formTipoProd.find('button[type="submit"]').prop('disabled', true);
            ejecutarAccionMasiva(payload)
                .done(function (resp) {
                    modalTipoProd ? modalTipoProd.hide() : $modalTipoProd.hide();
                    avisarResultadoAccionMasiva(resp, 'tipo_producto_id');
                })
                .fail(function () { toast('error', 'No se pudo aplicar la acción.'); })
                .always(function () { $btn.prop('disabled', false); });
        });
    });
})();
