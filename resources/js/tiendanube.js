/**
 * Configuración & Ajustes → Tiendanube (spec 015).
 * Credenciales de la Aplicación personalizada, panel de estado, modo sólo
 * lectura e historial — todo por AJAX, sin recarga de página (SC-006).
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

    const ESTADOS = {
        no_configurada: { etiqueta: 'No configurada', color: 'secondary' },
        desconectada: { etiqueta: 'Desconectada', color: 'secondary' },
        conectada: { etiqueta: 'Conectada', color: 'success' },
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
        const $pagina = $('#tn-panel-estado');
        if (!$pagina.length) {
            return;
        }

        const $modalCredenciales = $('#modal-credenciales-tn');
        const modalCredenciales = window.bootstrap ? new window.bootstrap.Modal($modalCredenciales[0]) : null;

        // --- Panel de estado ---
        function pintarEstado(resp) {
            const info = ESTADOS[resp.estado] || ESTADOS.no_configurada;
            $('#tn-badge-estado').attr('class', 'badge bg-' + info.color).text(info.etiqueta);

            const conf = resp.configuracion || {};
            $('#tn-info-store-id').text(conf.store_id || '—');
            $('#tn-info-token').text(conf.token_cargado ? 'Cargado' : 'Sin cargar');
            $('#tn-modo-solo-lectura').prop('checked', !!conf.modo_solo_lectura);
            $('#tn-aviso-solo-lectura').toggleClass('d-none', !conf.modo_solo_lectura);

            $('#tn-cred-store-id').val(conf.store_id || '');
            $('#tn-cred-token-cargado').toggle(!!conf.token_cargado);

            const tienda = resp.tienda;
            const conectada = resp.estado === 'conectada';
            const caida = resp.estado === 'caida';
            const noConfigurada = resp.estado === 'no_configurada';

            $('#tn-datos-tienda').toggle(!!tienda);
            if (tienda) {
                $('#tn-tienda-nombre').text(tienda.nombre || '—');
                $('#tn-tienda-dominio').text(tienda.dominio ? '(' + tienda.dominio + ')' : '');
                $('#tn-tienda-pais').text(tienda.pais || '—');
                $('#tn-tienda-moneda').text(tienda.moneda || '—');
                $('#tn-credenciales-guardadas').text(formatearFecha(conf.credenciales_guardadas_en));
                $('#tn-ultima-verificacion').text(formatearFecha(tienda.ultima_verificacion_en));
            }

            $('#btn-desconectar-tn').toggle(conectada || caida);

            $('#tn-aviso-caida').toggleClass('d-none', !caida);
            if (caida) {
                $('#tn-aviso-caida-mensaje').text(resp.ultimo_error || 'La conexión está caída.');
            }

            void noConfigurada;
        }

        function cargarEstado() {
            return $.getJSON(rutas.estado).done(pintarEstado).fail(function () {
                toast('error', 'No se pudo cargar el estado de la conexión.');
            });
        }

        // --- Credenciales ---
        $modalCredenciales.on('show.bs.modal', function () {
            limpiarErrores($('#form-credenciales-tn'));
            $('#tn-cred-access-token').val('');
        });

        $('#form-credenciales-tn').on('submit', function (e) {
            e.preventDefault();
            const $form = $(this);
            const datos = {
                store_id: $('#tn-cred-store-id').val(),
                access_token: $('#tn-cred-access-token').val(),
            };

            $('#btn-guardar-credenciales-tn').prop('disabled', true);
            $.ajax({ url: rutas.credenciales, method: 'PUT', dataType: 'json', data: datos })
                .done(function (resp) {
                    modalCredenciales ? modalCredenciales.hide() : $modalCredenciales.hide();
                    toast(resp.advertencia ? 'warning' : 'success', resp.advertencia || resp.mensaje);
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    mostrarErrores($form, resp.errors);
                    if (!resp.errors) {
                        toast('error', resp.message || 'No se pudo guardar la configuración.');
                    }
                })
                .always(function () { $('#btn-guardar-credenciales-tn').prop('disabled', false); });
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

        // --- Probar conexión ---
        $('#btn-probar-tn').on('click', function () {
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
        $('#btn-confirmar-desconectar-tn').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({ url: rutas.desconectar, method: 'POST', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    cargarEstado();
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo desconectar.');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                    const $modal = $('#modal-desconectar-tn');
                    const instancia = window.bootstrap ? window.bootstrap.Modal.getInstance($modal[0]) : null;
                    instancia ? instancia.hide() : $modal.hide();
                });
        });

        // --- Historial ---
        let tablaOperaciones = null;
        if ($.fn && $.fn.DataTable) {
            tablaOperaciones = $('#tabla-tn-operaciones').DataTable({
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
                    url: rutas.historial,
                    data: function (d) {
                        d.desde = $('#tn-historial-desde').val();
                        d.hasta = $('#tn-historial-hasta').val();
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
            });

            $('#btn-filtrar-historial-tn').on('click', function () {
                tablaOperaciones.ajax.reload();
            });
        }

        cargarEstado();
    });
})();
