/**
 * Configuración & Ajustes → Tiendanube (spec 022/024: conexión Application
 * REST clásica). Panel de estado, configuración de ventas, modo sólo lectura
 * e historial — todo por AJAX, sin recarga de página. "Conectar" es un link
 * normal (redirige fuera del sitio al Partner Portal), no un submit AJAX.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[tiendanube] jQuery no está disponible.');
        return;
    }

    const cfg = window.TiendanubeConfig || {};
    const rutas = cfg.rutas || {};

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
            console.log('[tiendanube][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function formatearFecha(iso) {
        if (!iso) {
            return '—';
        }
        try {
            return new Date(iso).toLocaleString('es-AR');
        } catch (e) {
            return iso;
        }
    }

    $(function () {
        const $pagina = $('#form-ventas-tn');
        if (!$pagina.length) {
            return;
        }

        const hasSelect2 = !!($.fn && $.fn.select2);
        if (hasSelect2) {
            $('#tn-deposito-id, #tn-cuenta-tesoreria-id, #tn-lista-precio-id').select2({ width: '100%', theme: 'default', allowClear: true });
        }

        // ---- Vendedor por defecto (catálogo con Select2 + crear/renombrar/eliminar, spec 020) ----
        let vendedores = (cfg.vendedores || []).slice();
        const $vendedorSel = $('#tn-vendedor-id');
        let vendedorPrevio = '';

        function actualizarBotonesVendedor() {
            const val = $vendedorSel.val();
            const real = !!val && val !== '__nuevo__';
            $('#btn-renombrar-vendedor, #btn-eliminar-vendedor').toggleClass('d-none', !real);
        }

        function refreshSelect2($el) {
            if (hasSelect2 && $el && $el.length && $el.hasClass('select2-hidden-accessible')) {
                $el.trigger('change.select2');
            }
        }

        function renderVendedores(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $vendedorSel.empty();
            $vendedorSel.append(new Option('Sin vendedor por defecto', '', false, !sel));
            $vendedorSel.append(new Option('＋ Crear Vendedor', '__nuevo__', false, false));
            vendedores.forEach((v) => $vendedorSel.append(new Option(v.nombre, v.id, false, String(v.id) === sel)));
            refreshSelect2($vendedorSel);
            vendedorPrevio = sel;
            actualizarBotonesVendedor();
        }

        if (hasSelect2) { $vendedorSel.select2({ width: '100%', theme: 'default', allowClear: true }); }
        renderVendedores('');

        $vendedorSel.on('change', function () {
            const val = $(this).val();
            if (val === '__nuevo__') {
                $(this).val(vendedorPrevio).trigger('change.select2');
                abrirModalVendedor('crear', '', '');
            } else {
                vendedorPrevio = val || '';
                actualizarBotonesVendedor();
            }
        });

        let modoVendedor = 'crear';
        let idVendedorEditar = null;

        function abrirModalVendedor(modo, id, nombreActual) {
            modoVendedor = modo;
            idVendedorEditar = id || null;
            $('#nuevo-vendedor-nombre').val(nombreActual || '').removeClass('is-invalid');
            $('#nuevo-vendedor-error').text('');
            $('#modal-nuevo-vendedor-titulo').text(modo === 'renombrar' ? 'Renombrar Vendedor' : 'Crear Vendedor');
            $('#btn-crear-vendedor').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nuevo-vendedor')).show();
            setTimeout(() => $('#nuevo-vendedor-nombre').trigger('focus'), 300);
        }

        $('#btn-renombrar-vendedor').on('click', function (e) {
            e.preventDefault();
            const id = $vendedorSel.val();
            if (!id || id === '__nuevo__') { return; }
            const v = vendedores.find((x) => String(x.id) === String(id));
            abrirModalVendedor('renombrar', id, v ? v.nombre : '');
        });

        $('#btn-crear-vendedor').on('click', function () {
            const nombre = $('#nuevo-vendedor-nombre').val().trim();
            $('#nuevo-vendedor-nombre').removeClass('is-invalid');
            $('#nuevo-vendedor-error').text('');
            if (!nombre) {
                $('#nuevo-vendedor-nombre').addClass('is-invalid');
                $('#nuevo-vendedor-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = modoVendedor === 'renombrar';
            const url = esRenombrar ? rutas.vendedorUpdateBase + '/' + idVendedorEditar : rutas.vendedorStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const v = vendedores.find((x) => String(x.id) === String(idVendedorEditar));
                        if (v) { v.nombre = resp.vendedor.nombre; }
                        vendedores.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderVendedores(idVendedorEditar);
                    } else {
                        vendedores.push({ id: resp.vendedor.id, nombre: resp.vendedor.nombre });
                        vendedores.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderVendedores(resp.vendedor.id);
                    }
                    bootstrap.Modal.getInstance(document.getElementById('modal-nuevo-vendedor'))?.hide();
                    toast('success', resp.mensaje || 'Vendedor guardado.');
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo guardar el vendedor.';
                    $('#nuevo-vendedor-nombre').addClass('is-invalid');
                    $('#nuevo-vendedor-error').text(msg);
                });
        });

        let idVendedorAEliminar = null;
        $('#btn-eliminar-vendedor').on('click', function (e) {
            e.preventDefault();
            const id = $vendedorSel.val();
            if (!id || id === '__nuevo__') { return; }
            const v = vendedores.find((x) => String(x.id) === String(id));
            idVendedorAEliminar = id;
            $('#vendedor-eliminar-nombre').text(v ? v.nombre : '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-vendedor-eliminar')).show();
        });
        $('#btn-confirmar-eliminar-vendedor').on('click', function () {
            if (!idVendedorAEliminar) { return; }
            const id = idVendedorAEliminar;
            $.post(rutas.vendedorDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    vendedores = vendedores.filter((x) => String(x.id) !== String(id));
                    renderVendedores('');
                    toast('success', resp.mensaje || 'Vendedor eliminado.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar el vendedor.'))
                .always(() => {
                    bootstrap.Modal.getInstance(document.getElementById('modal-vendedor-eliminar'))?.hide();
                    idVendedorAEliminar = null;
                });
        });

        // ---- Categoría de las Ventas (catálogo con Select2 + crear/renombrar/eliminar) ----
        let categorias = (cfg.categorias || []).slice();
        const $categoriaSel = $('#tn-categoria-venta-id');
        let categoriaPrevia = '';

        function actualizarBotonesCategoria() {
            const val = $categoriaSel.val();
            const cat = categorias.find((c) => String(c.id) === String(val));
            const real = !!val && val !== '__nuevo__' && !(cat && cat.es_sistema);
            $('#btn-renombrar-categoria, #btn-eliminar-categoria').toggleClass('d-none', !real);
        }

        function renderCategorias(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $categoriaSel.empty();
            $categoriaSel.append(new Option('Sin categoría', '', false, !sel));
            $categoriaSel.append(new Option('＋ Crear Categoría de ventas', '__nuevo__', false, false));
            categorias.forEach((c) => $categoriaSel.append(new Option(c.nombre, c.id, false, String(c.id) === sel)));
            refreshSelect2($categoriaSel);
            categoriaPrevia = sel;
            actualizarBotonesCategoria();
        }

        if (hasSelect2) { $categoriaSel.select2({ width: '100%', theme: 'default', allowClear: true }); }
        renderCategorias('');

        $categoriaSel.on('change', function () {
            const val = $(this).val();
            if (val === '__nuevo__') {
                $(this).val(categoriaPrevia).trigger('change.select2');
                abrirModalCategoria('crear', '', '');
            } else {
                categoriaPrevia = val || '';
                actualizarBotonesCategoria();
            }
        });

        let modoCategoria = 'crear';
        let idCategoriaEditar = null;

        function abrirModalCategoria(modo, id, nombreActual) {
            modoCategoria = modo;
            idCategoriaEditar = id || null;
            $('#nueva-categoria-nombre').val(nombreActual || '').removeClass('is-invalid');
            $('#nueva-categoria-error').text('');
            $('#modal-nueva-categoria-titulo').text(modo === 'renombrar' ? 'Renombrar Categoría de ventas' : 'Crear Categoría de ventas');
            $('#btn-crear-categoria').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria')).show();
            setTimeout(() => $('#nueva-categoria-nombre').trigger('focus'), 300);
        }

        $('#btn-renombrar-categoria').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categorias.find((x) => String(x.id) === String(id));
            abrirModalCategoria('renombrar', id, c ? c.nombre : '');
        });

        $('#btn-crear-categoria').on('click', function () {
            const nombre = $('#nueva-categoria-nombre').val().trim();
            $('#nueva-categoria-nombre').removeClass('is-invalid');
            $('#nueva-categoria-error').text('');
            if (!nombre) {
                $('#nueva-categoria-nombre').addClass('is-invalid');
                $('#nueva-categoria-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = modoCategoria === 'renombrar';
            const url = esRenombrar ? rutas.categoriaUpdateBase + '/' + idCategoriaEditar : rutas.categoriaVentaStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const c = categorias.find((x) => String(x.id) === String(idCategoriaEditar));
                        if (c) { c.nombre = resp.categoria.nombre; }
                        categorias.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderCategorias(idCategoriaEditar);
                    } else {
                        categorias.push({ id: resp.categoria.id, nombre: resp.categoria.nombre, es_sistema: false });
                        categorias.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderCategorias(resp.categoria.id);
                    }
                    bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria'))?.hide();
                    toast('success', resp.mensaje || 'Categoría guardada.');
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo guardar la categoría.';
                    $('#nueva-categoria-nombre').addClass('is-invalid');
                    $('#nueva-categoria-error').text(msg);
                });
        });

        let idCategoriaAEliminar = null;
        $('#btn-eliminar-categoria').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categorias.find((x) => String(x.id) === String(id));
            idCategoriaAEliminar = id;
            $('#categoria-eliminar-nombre').text(c ? c.nombre : '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-categoria-eliminar')).show();
        });
        $('#btn-confirmar-eliminar-categoria').on('click', function () {
            if (!idCategoriaAEliminar) { return; }
            const id = idCategoriaAEliminar;
            $.post(rutas.categoriaDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    categorias = categorias.filter((x) => String(x.id) !== String(id));
                    renderCategorias('');
                    toast('success', resp.mensaje || 'Categoría eliminada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la categoría.'))
                .always(() => {
                    bootstrap.Modal.getInstance(document.getElementById('modal-categoria-eliminar'))?.hide();
                    idCategoriaAEliminar = null;
                });
        });

        // --- Ventas de Tiendanube (spec 017) ---
        $('#form-ventas-tn').on('submit', function (e) {
            e.preventDefault();
            const datos = {
                creacion_automatica: $('#tn-creacion-automatica').is(':checked') ? 1 : 0,
                frecuencia_sync_minutos: $('#tn-frecuencia-sync').val(),
                deposito_id: $('#tn-deposito-id').val() || null,
                categoria_venta_id: $('#tn-categoria-venta-id').val() || null,
                cuenta_tesoreria_id: $('#tn-cuenta-tesoreria-id').val() || null,
                dias_primera_sync: $('#tn-dias-primera-sync').val(),
                lista_precio_id: $('#tn-lista-precio-id').val() || null,
                vendedor_id: $('#tn-vendedor-id').val() || null,
            };

            window.AppBtn.loading('#btn-guardar-ventas-tn', true);
            $.ajax({ url: rutas.guardarVentas, method: 'PATCH', dataType: 'json', data: datos })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    cargarEstadoRest();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.message || 'No se pudo guardar la configuración de ventas.');
                })
                .always(function () { window.AppBtn.loading('#btn-guardar-ventas-tn', false); });
        });

        // --- Modo sólo lectura ---
        $('#tn-modo-solo-lectura').on('change', function () {
            const $checkbox = $(this);
            const activo = $checkbox.is(':checked');
            $.ajax({ url: rutas.modoSoloLectura, method: 'PATCH', dataType: 'json', data: { activo: activo ? 1 : 0 } })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    $('#tn-aviso-solo-lectura').toggleClass('d-none', !resp.modo_solo_lectura);
                })
                .fail(function () {
                    $checkbox.prop('checked', !activo);
                    toast('error', 'No se pudo actualizar el modo sólo lectura.');
                });
        });

        // --- Historial ---
        let tablaOperaciones = null;
        if ($.fn && $.fn.DataTable) {
            tablaOperaciones = $('#tabla-tn-operaciones').DataTable({
                processing: true,
                serverSide: true,
                order: [[0, 'desc']],
                language: {
                    search: 'Buscar:',
                    lengthMenu: 'Mostrar _MENU_ registros',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ operaciones',
                    infoEmpty: 'Sin operaciones',
                    infoFiltered: '(filtrado de _MAX_ en total)',
                    zeroRecords: 'No se encontraron operaciones',
                    paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                    processing: 'Cargando...',
                },
                ajax: {
                    url: rutas.historial,
                    data: function (d) {
                        d.desde = AppFecha.get($('#tn-historial-desde'));
                        d.hasta = AppFecha.get($('#tn-historial-hasta'));
                        d.resultado = $('#tn-historial-resultado').val();
                    },
                },
                columns: [
                    { data: 'created_at', name: 'created_at' },
                    { data: 'operacion', name: 'operacion' },
                    { data: 'sentido', name: 'sentido' },
                    {
                        data: 'resultado', name: 'resultado',
                        render: function (resultado) {
                            const colores = { exito: 'success', error: 'danger', bloqueada: 'warning' };
                            return '<span class="badge bg-' + (colores[resultado] || 'secondary') + '">' + resultado + '</span>';
                        },
                    },
                    { data: 'codigo_http', name: 'codigo_http', defaultContent: '—' },
                    {
                        data: 'duracion_ms', name: 'duracion_ms', defaultContent: '—',
                        render: function (ms) { return ms ? ms + ' ms' : '—'; },
                    },
                    { data: 'mensaje_error', name: 'mensaje_error', defaultContent: '—', orderable: false },
                ],
                stateSave: true,
                buttons: [
                    {
                        extend: 'colvis',
                        text: '<i class="fas fa-table-columns"></i>',
                        className: 'btn btn-outline-secondary',
                    },
                ],
            });

            $('#tabla-tn-operaciones').one('init.dt', function () {
                tablaOperaciones.buttons().container().appendTo('#dt-buttons-tn-operaciones');
            });

            $('#btn-filtrar-historial-tn').on('click', function () {
                tablaOperaciones.ajax.reload();
            });
        }

        const ESTADOS_REST = {
            no_configurada: { etiqueta: 'No configurada', color: 'secondary' },
            conectada: { etiqueta: 'Conectada', color: 'success' },
            caida: { etiqueta: 'Caída', color: 'danger' },
        };

        function pintarEstadoRest(resp) {
            const info = ESTADOS_REST[resp.estado] || ESTADOS_REST.no_configurada;
            $('#tn-rest-badge-estado').attr('class', 'badge bg-' + info.color).text(info.etiqueta);

            const conexion = resp.conexion || {};
            const conectada = resp.estado === 'conectada';
            const caida = resp.estado === 'caida';

            $('#tn-rest-datos-conexion').toggle(conectada || caida);
            if (conectada || caida) {
                $('#tn-rest-conectada-en').text(formatearFecha(conexion.conectada_en));
                $('#tn-rest-tienda-nombre').text(conexion.tienda_nombre || '—');
                $('#tn-rest-tienda-dominio').text(conexion.tienda_dominio || '—');
                $('#tn-rest-scopes-otorgados').text(conexion.scopes_otorgados || '—');
            }

            $('#btn-conectar-tn-rest').toggle(!conectada);
            $('#btn-desconectar-tn-rest').toggle(conectada || caida);

            $('#tn-rest-aviso-caida').toggleClass('d-none', !caida);
            if (caida) {
                $('#tn-rest-aviso-caida-mensaje').text(resp.ultimo_error || 'La conexión está caída. Volvé a conectar.');
            }

            // spec 024 (retiro MCP) — configuración de ventas, ahora parte de esta misma respuesta.
            $('#tn-modo-solo-lectura').prop('checked', !!conexion.modo_solo_lectura);
            $('#tn-aviso-solo-lectura').toggleClass('d-none', !conexion.modo_solo_lectura);
            $('#tn-creacion-automatica').prop('checked', !!conexion.creacion_automatica);
            $('#tn-frecuencia-sync').val(conexion.frecuencia_sync_minutos || 15);
            $('#tn-dias-primera-sync').val(conexion.dias_primera_sync || 30);
            if ($('#tn-deposito-id').val() !== undefined) {
                $('#tn-deposito-id').val(conexion.deposito_id || '').trigger('change.select2');
            }
            if ($('#tn-categoria-venta-id').val() !== undefined) {
                renderCategorias(conexion.categoria_venta_id || '');
            }
            if ($('#tn-cuenta-tesoreria-id').val() !== undefined) {
                $('#tn-cuenta-tesoreria-id').val(conexion.cuenta_tesoreria_id || '').trigger('change.select2');
            }
            if ($('#tn-lista-precio-id').val() !== undefined) {
                $('#tn-lista-precio-id').val(conexion.lista_precio_id || '').trigger('change.select2');
            }
            if ($('#tn-vendedor-id').val() !== undefined) {
                $('#tn-vendedor-id').val(conexion.vendedor_id || '').trigger('change.select2');
                vendedorPrevio = conexion.vendedor_id ? String(conexion.vendedor_id) : '';
                actualizarBotonesVendedor();
            }
            $('#tn-ultima-sync-info').text(
                conexion.ultima_sync_en
                    ? 'Última sincronización: ' + formatearFecha(conexion.ultima_sync_en) + (conexion.ultima_sync_resultado ? ' — ' + conexion.ultima_sync_resultado : '')
                    : 'Todavía no se sincronizó ninguna orden.'
            );
            $('#tn-stock-ultima-sync-info').text(
                conexion.stock_ultima_sync_en
                    ? 'Última sincronización de stock: ' + formatearFecha(conexion.stock_ultima_sync_en) + (conexion.stock_ultima_sync_resultado ? ' — ' + conexion.stock_ultima_sync_resultado : '')
                    : 'Todavía no se sincronizó stock hacia Tiendanube.'
            );
        }

        function cargarEstadoRest() {
            $.getJSON(rutas.estadoRest).done(pintarEstadoRest).fail(function () {
                toast('error', 'No se pudo cargar el estado de la conexión.');
            });
        }

        $('#btn-confirmar-desconectar-tn-rest').on('click', function () {
            const $btn = window.AppBtn.loading($(this), true);
            $.ajax({ url: rutas.desconectarRest, method: 'POST', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    cargarEstadoRest();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo desconectar.');
                })
                .always(function () {
                    window.AppBtn.loading($btn, false);
                    const $modal = $('#modal-desconectar-tn-rest');
                    const instancia = window.bootstrap ? window.bootstrap.Modal.getInstance($modal[0]) : null;
                    instancia ? instancia.hide() : $modal.hide();
                });
        });

        if ($('#tn-rest-panel-estado').length) {
            cargarEstadoRest();
        }
    });
})();
