/**
 * Modales compartidos de Producto (Ver / Editar) — extraídos de productos.js
 * (spec 052) para poder reutilizarse desde Ventas, Presupuestos y Compras sin
 * duplicar la lógica del formulario de Productos (variantes, listas de precio,
 * tipos de producto, imagen).
 *
 * Se auto-inicializa si detecta el modal de edición (#modal-producto) en el
 * DOM — no depende del DataTable de Productos (#tabla-productos). Expone
 * `window.ProductoModales.abrirVer(id)` / `.abrirEditar(id)` para apertura
 * programática, y sigue soportando la delegación por clase `.js-producto-ver`
 * / `.js-producto-editar` para compatibilidad con el listado de Productos.
 *
 * Al guardar una edición con éxito, dispara `producto:actualizado` en
 * `document` con `detail: { producto }` — quien lo escuche (productos.js,
 * ventas.js, presupuestos.js, compras.js) decide qué refrescar.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[producto-modales] jQuery no está disponible.');
        return;
    }

    const cfg = window.ProductosConfig || {};
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
            console.log('[producto-modales][' + tipo + ']', mensaje);
        }
    }

    function esc(v) {
        return (v === null || v === undefined) ? '' : $('<div>').text(v).html();
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    $(function () {
        const $modal = $('#modal-producto');
        if (!$modal.length) {
            return;
        }

        const modal = window.bootstrap ? new window.bootstrap.Modal($modal[0]) : null;
        const $form = $('#form-producto');

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }
        function refreshSelect2($el) {
            if (hasSelect2 && $el && $el.length && $el.hasClass('select2-hidden-accessible')) {
                $el.trigger('change.select2');
            }
        }

        initSelect2($form.find('select[name="stock_inicial_deposito_id"]'), { dropdownParent: $modal });
        initSelect2($('#producto-proveedor'), { dropdownParent: $modal, placeholder: 'Elija Proveedor', allowClear: true });

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
            window.AppBtn.loading('#btn-guardar-tipo-nombre', true);
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
                .always(function () { window.AppBtn.loading('#btn-guardar-tipo-nombre', false); });
        });

        $('#btn-renombrar-tipo-producto').on('click', function (e) {
            e.preventDefault();
            const id = $tipoProdSel.val();
            if (!id || id === '__nuevo__') { return; }
            const t = tiposProducto.find(function (x) { return String(x.id) === String(id); });
            abrirModalTipoNombre('renombrar', id, t ? t.nombre : '');
        });

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
            $('#seccion-variantes').addClass('d-none');
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

        $('#producto-imagen').on('change', function () {
            const file = this.files && this.files[0];
            if (file) {
                $('#producto-imagen-eliminar').val('0');
                mostrarPreviewImagen(URL.createObjectURL(file));
            }
        });

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

        // --- Variantes dinámicas ---
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

        // --- Listas de precio: catálogo global gestionable inline ---
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

            window.AppBtn.loading('#btn-guardar-lista-nombre', true);
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
                .always(function () { window.AppBtn.loading('#btn-guardar-lista-nombre', false); });
        });

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

        // ================== EDITAR ==================
        function abrirEditar(id) {
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
                        const $checkbox = $input.filter(':checkbox');
                        const $radios = $input.filter(':radio');
                        if ($checkbox.length) {
                            $checkbox.prop('checked', !!p[campo] && p[campo] != 0);
                        } else if ($radios.length) {
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
                    abrirModal('Editar Producto');
                })
                .fail(function () {
                    toast('error', 'No se pudo cargar el producto.');
                });
        }

        $(document).on('click', '.js-producto-editar', function (e) {
            e.preventDefault();
            abrirEditar($(this).data('id'));
        });

        // ================== VER (sólo lectura, fiel a Contagram informe §4.7) ==================
        const $modalVer = $('#modal-producto-ver');
        const modalVer = window.bootstrap ? new window.bootstrap.Modal($modalVer[0]) : null;
        let verProductoId = null;

        function moneda(v) {
            return '$ ' + Number(v || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function nombrePorId(lista, id) {
            const item = (lista || []).find(function (x) { return String(x.id) === String(id); });
            return item ? item.nombre : null;
        }

        function etiquetaIva(campo, valor) {
            const $opt = $form.find('select[name="' + campo + '"] option[value="' + valor + '"]');
            return $opt.length ? $opt.text() : (valor || '—');
        }

        function abrirVer(id) {
            verProductoId = id;
            $.getJSON(rutas.show + '/' + id)
                .done(function (resp) {
                    const p = resp.producto;
                    $('#ver-producto-nombre').text(p.nombre || '—');
                    $('#ver-producto-codigo').text(p.codigo ? ('Código: ' + p.codigo) : 'Sin código').toggleClass('d-none', !p.codigo);
                    $('#ver-producto-estado')
                        .text(p.activo ? 'Activo' : 'Inactivo')
                        .removeClass('bg-success bg-secondary')
                        .addClass(p.activo ? 'bg-success' : 'bg-secondary');
                    $('#ver-producto-tipo').text(p.tipo === 'servicio' ? 'Servicio' : 'Producto');

                    $('#ver-producto-tipo-producto').text(nombrePorId(cfg.tiposProducto, p.tipo_producto_id) || '—');
                    $('#ver-producto-proveedor').text(nombrePorId(cfg.proveedores, p.proveedor_id) || '—');

                    const esServicio = p.tipo === 'servicio';
                    $('#ver-producto-stock-wrap').toggleClass('d-none', esServicio);
                    $('#ver-producto-stock').text(esServicio ? '—' : Number(p.stock_total || 0).toLocaleString('es-AR'));

                    $('#ver-producto-costo').text(moneda(p.costo));
                    $('#ver-producto-precio-venta').text(moneda(p.precio_venta));
                    $('#ver-producto-iva-venta').text(etiquetaIva('iva_venta_pct', p.iva_venta_pct));
                    $('#ver-producto-iva-compra').text(etiquetaIva('iva_compra_pct', p.iva_compra_pct));

                    const mostrarEn = [];
                    if (p.mostrar_en_ventas) { mostrarEn.push('Ventas'); }
                    if (p.mostrar_en_compras) { mostrarEn.push('Compras'); }
                    $('#ver-producto-mostrar-en').text(mostrarEn.length ? mostrarEn.join(' y ') : 'No se muestra en Ventas ni Compras');

                    const precios = p.precios || [];
                    $('#ver-producto-listas-wrap').toggleClass('d-none', precios.length === 0);
                    const $body = $('#ver-producto-listas-body').empty();
                    precios.forEach(function (precio) {
                        const nombreLista = nombrePorId(cfg.listasPrecio, precio.lista_precio_id) || ('Lista ' + precio.lista_precio_id);
                        $body.append(
                            $('<tr>').append(
                                $('<td>').text(esc(nombreLista)),
                                $('<td>').addClass('text-end fw-semibold').text(moneda(precio.precio))
                            )
                        );
                    });

                    $('#ver-producto-descripcion-wrap').toggleClass('d-none', !p.descripcion);
                    $('#ver-producto-descripcion').text(p.descripcion || '');

                    const $img = $('#ver-producto-imagen');
                    if (p.imagen_url) {
                        $img.attr('src', p.imagen_url).removeClass('d-none');
                    } else {
                        $img.attr('src', '').addClass('d-none');
                    }

                    modalVer ? modalVer.show() : $modalVer.show();
                })
                .fail(function () {
                    toast('error', 'No se pudo cargar el producto.');
                });
        }

        $(document).on('click', '.js-producto-ver', function (e) {
            e.preventDefault();
            abrirVer($(this).data('id'));
        });

        // Desde "Ver" → botón "Editar": cierra el modal de vista y abre el de edición.
        $('#btn-ver-editar').on('click', function () {
            if (!verProductoId) { return; }
            const id = verProductoId;
            modalVer ? modalVer.hide() : $modalVer.hide();
            abrirEditar(id);
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

        // Submit (store/update). Al guardar, dispara 'producto:actualizado' en vez
        // de asumir un DataTable propio — cada página consumidora decide qué hacer.
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

            window.AppBtn.loading('#btn-guardar-producto', true);

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
                    toast('success', resp.mensaje || 'Guardado.');
                    document.dispatchEvent(new CustomEvent('producto:actualizado', { detail: { producto: resp.producto } }));
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
                    window.AppBtn.loading('#btn-guardar-producto', false);
                });
        });

        window.ProductoModales = { abrirVer: abrirVer, abrirEditar: abrirEditar };
    });
})();
