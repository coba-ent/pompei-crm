/**
 * Configuración & Ajustes → Mercado Libre (spec 011).
 * Credenciales de la aplicación, panel de estado, vinculación OAuth, modo sólo
 * lectura e historial — todo por AJAX, sin recarga de página (SC-009).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[mercadolibre] jQuery no está disponible.');
        return;
    }

    const cfg = window.MercadoLibreConfig || {};
    const rutas = cfg.rutas || {};
    const sitios = cfg.sitios || [];

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
            console.log('[mercadolibre][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const ESTADOS = {
        no_configurada: { etiqueta: 'No configurada', color: 'secondary' },
        desconectada: { etiqueta: 'Desconectada', color: 'secondary' },
        conectada: { etiqueta: 'Conectada', color: 'success' },
        pendiente_confirmacion: { etiqueta: 'Pendiente de confirmación', color: 'warning' },
        caida: { etiqueta: 'Caída', color: 'danger' },
    };

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

    /** FR-024: cuánto falta (o hace) para el vencimiento del acceso, en formato legible. */
    function formatearFaltante(iso) {
        if (!iso) {
            return '';
        }
        const diffMs = new Date(iso).getTime() - Date.now();
        const vencido = diffMs < 0;
        const minutos = Math.round(Math.abs(diffMs) / 60000);
        const horas = Math.floor(minutos / 60);
        const minutosRestantes = minutos % 60;
        const texto = horas > 0 ? (horas + 'h ' + minutosRestantes + 'min') : (minutosRestantes + 'min');

        return vencido ? ' (venció hace ' + texto + ')' : ' (en ' + texto + ')';
    }

    function limpiarErrores($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.invalid-feedback').text('');
    }

    function mostrarErrores($form, errors) {
        limpiarErrores($form);
        $.each(errors || {}, function (campo, mensajes) {
            $form.find('[name="' + campo + '"]').addClass('is-invalid');
            $form.find('[data-field="' + campo + '"]').text(mensajes[0]);
        });
    }

    $(function () {
        const $pagina = $('#ml-panel-estado');
        if (!$pagina.length) {
            return;
        }

        const hasSelect2 = !!($.fn && $.fn.select2);
        const $modalCredenciales = $('#modal-credenciales-ml');
        const modalCredenciales = window.bootstrap ? new window.bootstrap.Modal($modalCredenciales[0]) : null;

        if (hasSelect2) {
            $('#ml-cred-site-id').select2({
                width: '100%',
                theme: 'default',
                dropdownParent: $modalCredenciales,
                data: sitios.map(function (s) { return { id: s.id, text: s.texto }; }),
            });
            $('#ml-deposito-id, #ml-lista-precio-id').select2({ width: '100%', theme: 'default', allowClear: true });
        } else {
            const $select = $('#ml-cred-site-id');
            sitios.forEach(function (s) {
                $select.append($('<option>', { value: s.id, text: s.texto }));
            });
        }

        // ---- Vendedor por defecto (catálogo con Select2 + crear/renombrar/eliminar, spec 020) ----
        let vendedores = (cfg.vendedores || []).slice();
        const $vendedorSel = $('#ml-vendedor-id');
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
        const $categoriaSel = $('#ml-categoria-venta-id');
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

        // --- Panel de estado ---
        function pintarEstado(resp) {
            const info = ESTADOS[resp.estado] || ESTADOS.no_configurada;
            $('#ml-badge-estado').attr('class', 'badge bg-' + info.color).text(info.etiqueta);

            const conf = resp.configuracion || {};
            $('#ml-info-client-id').text(conf.client_id || '—');
            $('#ml-info-secret').text(conf.secret_cargado ? 'Cargada' : 'Sin cargar');
            $('#ml-info-site').text(conf.site_id || '—');
            $('#ml-modo-solo-lectura').prop('checked', !!conf.modo_solo_lectura);
            $('#ml-aviso-solo-lectura').toggleClass('d-none', !conf.modo_solo_lectura);

            // spec 012 — configuración de ventas.
            $('#ml-creacion-automatica').prop('checked', !!conf.creacion_automatica);
            $('#ml-frecuencia-sync').val(conf.frecuencia_sync_minutos || 15);
            $('#ml-dias-primera-sync').val(conf.dias_primera_sync || 30);
            if ($('#ml-deposito-id').val() !== undefined) {
                $('#ml-deposito-id').val(conf.deposito_id || '').trigger('change.select2');
            }
            if ($('#ml-categoria-venta-id').val() !== undefined) {
                renderCategorias(conf.categoria_venta_id || '');
            }
            if ($('#ml-lista-precio-id').val() !== undefined) {
                $('#ml-lista-precio-id').val(conf.lista_precio_id || '').trigger('change.select2');
            }
            if ($('#ml-vendedor-id').val() !== undefined) {
                $('#ml-vendedor-id').val(conf.vendedor_id || '').trigger('change.select2');
                vendedorPrevio = conf.vendedor_id ? String(conf.vendedor_id) : '';
                actualizarBotonesVendedor();
            }
            $('#ml-ultima-sync-info').text(
                conf.ultima_sync_en
                    ? 'Última sincronización: ' + formatearFecha(conf.ultima_sync_en) + (conf.ultima_sync_resultado ? ' — ' + conf.ultima_sync_resultado : '')
                    : 'Todavía no se sincronizó ninguna orden.'
            );
            // spec 013 — última corrida de sincronización de stock.
            $('#ml-stock-ultima-sync-info').text(
                conf.stock_ultima_sync_en
                    ? 'Última sincronización de stock: ' + formatearFecha(conf.stock_ultima_sync_en) + (conf.stock_ultima_sync_resultado ? ' — ' + conf.stock_ultima_sync_resultado : '')
                    : 'Todavía no se sincronizó stock hacia Mercado Libre.'
            );

            $('#ml-redirect-uri').val(resp.redirect_uri || '');

            $('#ml-cred-client-id').val(conf.client_id || '');
            $('#ml-cred-secret-cargado').toggle(!!conf.secret_cargado);

            const $adv = $('#ml-advertencias-entorno');
            $adv.empty();
            (resp.advertencias || []).forEach(function (mensaje) {
                $adv.append($('<div class="alert alert-warning mb-2"><i class="fas fa-triangle-exclamation me-1"></i></div>').append(document.createTextNode(mensaje)));
            });

            const cuenta = resp.cuenta;
            const conectada = resp.estado === 'conectada';
            const caida = resp.estado === 'caida';
            const noConfigurada = resp.estado === 'no_configurada';

            $('#ml-datos-cuenta').toggle(!!cuenta && (conectada || caida));
            if (cuenta) {
                $('#ml-cuenta-nickname').text(cuenta.nickname || '—');
                $('#ml-cuenta-tipo').text(cuenta.tipo_cuenta ? '(' + cuenta.tipo_cuenta + ')' : '');
                $('#ml-cuenta-id').text(cuenta.ml_user_id || '—');
                $('#ml-cuenta-email').text(cuenta.email || '—');
                $('#ml-cuenta-site').text(cuenta.site_id || '—');
                $('#ml-cuenta-vinculada').text(formatearFecha(cuenta.vinculada_en));
                $('#ml-cuenta-vence').text(formatearFecha(cuenta.token_expira_en) + formatearFaltante(cuenta.token_expira_en));
                $('#ml-cuenta-ultimo-refresh').text(formatearFecha(cuenta.ultimo_refresh_en));
            }

            $('#btn-conectar-ml').toggle(!noConfigurada && !conectada).attr('href', rutas.conectar);
            $('#btn-probar-ml').toggle(conectada);
            $('#btn-desconectar-ml').toggle(conectada || caida);

            $('#ml-aviso-caida').toggleClass('d-none', !caida);
            if (caida && cuenta) {
                $('#ml-aviso-caida-mensaje').text(cuenta.ultimo_error || 'La conexión está caída.');
            }
        }

        function cargarEstado() {
            return $.getJSON(rutas.estado).done(pintarEstado).fail(function () {
                toast('error', 'No se pudo cargar el estado de la conexión.');
            });
        }

        $('#ml-aviso-caida-reconectar').on('click', function (e) {
            e.preventDefault();
            window.location.href = rutas.conectar;
        });

        // --- Ventas de Mercado Libre (spec 012) ---
        $('#form-ventas-ml').on('submit', function (e) {
            e.preventDefault();
            const datos = {
                creacion_automatica: $('#ml-creacion-automatica').is(':checked') ? 1 : 0,
                frecuencia_sync_minutos: $('#ml-frecuencia-sync').val(),
                deposito_id: $('#ml-deposito-id').val() || null,
                categoria_venta_id: $('#ml-categoria-venta-id').val() || null,
                lista_precio_id: $('#ml-lista-precio-id').val() || null,
                vendedor_id: $('#ml-vendedor-id').val() || null,
                dias_primera_sync: $('#ml-dias-primera-sync').val(),
            };

            $('#btn-guardar-ventas-ml').prop('disabled', true);
            $.ajax({ url: rutas.guardarVentas, method: 'PATCH', dataType: 'json', data: datos })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.message || 'No se pudo guardar la configuración de ventas.');
                })
                .always(function () { $('#btn-guardar-ventas-ml').prop('disabled', false); });
        });

        // --- Credenciales ---
        $modalCredenciales.on('show.bs.modal', function () {
            limpiarErrores($('#form-credenciales-ml'));
            $('#ml-cred-client-secret').val('');
        });

        $('#form-credenciales-ml').on('submit', function (e) {
            e.preventDefault();
            const $form = $(this);
            const datos = {
                client_id: $('#ml-cred-client-id').val(),
                client_secret: $('#ml-cred-client-secret').val(),
                site_id: $('#ml-cred-site-id').val(),
            };

            $('#btn-guardar-credenciales-ml').prop('disabled', true);
            $.ajax({ url: rutas.guardar, method: 'PUT', dataType: 'json', data: datos })
                .done(function (resp) {
                    modalCredenciales ? modalCredenciales.hide() : $modalCredenciales.hide();
                    toast(resp.requiere_revinculacion ? 'warning' : 'success', resp.mensaje);
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    mostrarErrores($form, resp.errors);
                    if (!resp.errors) {
                        toast('error', resp.message || 'No se pudo guardar la configuración.');
                    }
                })
                .always(function () { $('#btn-guardar-credenciales-ml').prop('disabled', false); });
        });

        // --- Copiar Redirect URI ---
        $('#btn-copiar-redirect-uri').on('click', function () {
            const valor = $('#ml-redirect-uri').val();
            if (!valor) {
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(valor).then(function () {
                    toast('success', 'Redirect URI copiada.');
                }).catch(function () {
                    toast('error', 'No se pudo copiar. Copiala manualmente.');
                });
            } else {
                const $input = $('#ml-redirect-uri')[0];
                $input.select();
                document.execCommand('copy');
                toast('success', 'Redirect URI copiada.');
            }
        });

        // --- Modo sólo lectura ---
        $('#ml-modo-solo-lectura').on('change', function () {
            const $checkbox = $(this);
            const activo = $checkbox.is(':checked');
            $.ajax({ url: rutas.modoSoloLectura, method: 'PATCH', dataType: 'json', data: { activo: activo ? 1 : 0 } })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    $('#ml-aviso-solo-lectura').toggleClass('d-none', !resp.modo_solo_lectura);
                })
                .fail(function () {
                    $checkbox.prop('checked', !activo);
                    toast('error', 'No se pudo actualizar el modo sólo lectura.');
                });
        });

        // --- Probar conexión ---
        $('#btn-probar-ml').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({ url: rutas.probar, method: 'POST', dataType: 'json' })
                .done(function (resp) {
                    toast(resp.ok ? 'success' : 'error', resp.mensaje);
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo probar la conexión.');
                })
                .always(function () { $btn.prop('disabled', false); });
        });

        // --- Desconectar ---
        $('#btn-confirmar-desconectar-ml').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({ url: rutas.desconectar, method: 'DELETE', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo desconectar la cuenta.');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                    const $modal = $('#modal-desconectar-ml');
                    const instancia = window.bootstrap ? window.bootstrap.Modal.getInstance($modal[0]) : null;
                    instancia ? instancia.hide() : $modal.hide();
                });
        });

        // --- Reemplazo de cuenta pendiente (FR-022) ---
        const $modalReemplazo = $('#modal-reemplazo-cuenta-ml');
        const modalReemplazo = window.bootstrap ? new window.bootstrap.Modal($modalReemplazo[0]) : null;

        function chequearPendiente() {
            $.getJSON(rutas.pendiente).done(function (resp) {
                if (!resp.pendiente) {
                    return;
                }
                const p = resp.pendiente;
                $('#ml-reemplazo-actual-nickname').text((p.cuenta_actual && p.cuenta_actual.nickname) || '—');
                $('#ml-reemplazo-actual-id').text((p.cuenta_actual && p.cuenta_actual.ml_user_id) || '—');
                $('#ml-reemplazo-nueva-nickname').text((p.cuenta_nueva && p.cuenta_nueva.nickname) || '—');
                $('#ml-reemplazo-nueva-id').text((p.cuenta_nueva && p.cuenta_nueva.ml_user_id) || '—');
                $('#ml-reemplazo-nueva-email').text((p.cuenta_nueva && p.cuenta_nueva.email) || '—');
                $('#ml-reemplazo-expira').text(formatearFecha(p.expira_en));
                modalReemplazo ? modalReemplazo.show() : $modalReemplazo.show();
            });
        }

        $('#btn-confirmar-reemplazo-ml').on('click', function () {
            $.ajax({ url: rutas.confirmarReemplazo, method: 'POST', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    modalReemplazo ? modalReemplazo.hide() : $modalReemplazo.hide();
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo confirmar el reemplazo.');
                });
        });

        $('#btn-descartar-reemplazo-ml').on('click', function () {
            $.ajax({ url: rutas.descartarPendiente, method: 'DELETE', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    modalReemplazo ? modalReemplazo.hide() : $modalReemplazo.hide();
                    cargarEstado();
                })
                .fail(function () {
                    toast('error', 'No se pudo descartar la autorización pendiente.');
                });
        });

        // --- Historial ---
        let tablaOperaciones = null;
        if ($.fn && $.fn.DataTable) {
            tablaOperaciones = $('#tabla-ml-operaciones').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
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
                    url: rutas.operaciones,
                    data: function (d) {
                        d.desde = $('#ml-historial-desde').val();
                        d.hasta = $('#ml-historial-hasta').val();
                        d.resultado = $('#ml-historial-resultado').val();
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
            });

            $('#btn-filtrar-historial').on('click', function () {
                tablaOperaciones.ajax.reload();
            });
        }

        cargarEstado();
        chequearPendiente();
    });
})();
