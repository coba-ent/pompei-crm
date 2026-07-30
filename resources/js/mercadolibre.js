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
            $('#ml-deposito-id, #ml-categoria-venta-id, #ml-lista-precio-id').select2({ width: '100%', theme: 'default', allowClear: true });
        } else {
            const $select = $('#ml-cred-site-id');
            sitios.forEach(function (s) {
                $select.append($('<option>', { value: s.id, text: s.texto }));
            });
        }

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
                $('#ml-categoria-venta-id').val(conf.categoria_venta_id || '').trigger('change.select2');
            }
            if ($('#ml-lista-precio-id').val() !== undefined) {
                $('#ml-lista-precio-id').val(conf.lista_precio_id || '').trigger('change.select2');
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
