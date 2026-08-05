/**
 * Módulo Tesorería — Saldos (refresco AJAX por fecha de corte), configuración
 * de cuentas (modal + catálogo agrupado por tipo, mismo patrón sin DataTables
 * que Depósitos — catálogo chico) y Movimiento entre Cuentas (Select2 ajax
 * mostrando saldo). US4/US5 (ficha y flujo) se agregan en sus propias fases.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[tesoreria] jQuery no está disponible.');
        return;
    }

    const cfg = window.TesoreriaConfig || {};
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
            console.log('[tesoreria][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function esc(texto) {
        return $('<div>').text(texto == null ? '' : texto).html();
    }

    function fmtMoney(n) {
        return '$ ' + new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
    }

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }

    $(function () {
        // ============================================================
        // Saldos (US1)
        // ============================================================
        const $tablaACobrar = $('#tabla-a-cobrar tbody');
        if ($tablaACobrar.length) {
            function renderBloque($tbody, cuentas) {
                $tbody.empty();
                (cuentas || []).forEach(function (c) {
                    let $nombre;
                    if (c.id) {
                        $nombre = $('<a>').attr('href', rutas.cuentasBase + '/' + c.id).text(c.nombre);
                    } else if (c.nombre === 'Saldo Cta Cte Clientes') {
                        $nombre = $('<a>').attr('href', rutas.cuentaCorrienteClientes).text(c.nombre);
                    } else {
                        $nombre = $('<span>').text(c.nombre);
                    }
                    $tbody.append(
                        $('<tr>').append(
                            $('<td>').append($nombre),
                            $('<td>').addClass('text-end').text(fmtMoney(c.saldo))
                        )
                    );
                });
            }

            function renderSaldos(saldos) {
                renderBloque($tablaACobrar, saldos.a_cobrar.cuentas);
                $('#total-a-cobrar').text(fmtMoney(saldos.a_cobrar.total));

                renderBloque($('#tabla-a-pagar tbody'), saldos.a_pagar.cuentas);
                $('#total-a-pagar').text(fmtMoney(saldos.a_pagar.total));

                renderBloque($('#tabla-cajas tbody'), saldos.disponible.cajas.cuentas);
                $('#total-cajas').text(fmtMoney(saldos.disponible.cajas.total));

                renderBloque($('#tabla-bancos tbody'), saldos.disponible.bancos.cuentas);
                $('#total-bancos').text(fmtMoney(saldos.disponible.bancos.total));

                $('#total-disponible').text(fmtMoney(saldos.disponible.total));
            }

            function cargarSaldos(fecha) {
                $.getJSON(rutas.saldosData, { fecha: fecha }).done(renderSaldos).fail(function () {
                    toast('error', 'No se pudieron cargar los saldos.');
                });
            }

            if (cfg.saldosIniciales) {
                renderSaldos(cfg.saldosIniciales);
            } else {
                cargarSaldos($('#tesoreria-fecha-corte').val());
            }

            $('#tesoreria-fecha-corte').on('change', function () {
                cargarSaldos($(this).val());
            });

            window.TesoreriaSaldos = { recargar: cargarSaldos, fechaActual: function () { return $('#tesoreria-fecha-corte').val(); } };
        }

        // ============================================================
        // Configuración de cuentas (US2)
        // ============================================================
        const ETIQUETAS_TIPO = { a_cobrar: 'A Cobrar', a_pagar: 'A Pagar', banco: 'Banco', efectivo: 'Efectivo' };
        const $modalConfig = $('#modal-config-cuentas');
        const modalConfig = window.bootstrap && $modalConfig.length ? new window.bootstrap.Modal($modalConfig[0]) : null;

        function renderGrupos(grupos) {
            const $cont = $('#config-cuentas-grupos').empty();
            Object.keys(ETIQUETAS_TIPO).forEach(function (tipo) {
                const cuentas = grupos[tipo] || [];
                if (!cuentas.length) { return; }
                const $tabla = $('<table class="table table-sm mb-4"><thead><tr>' +
                    '<th>' + ETIQUETAS_TIPO[tipo] + '</th><th></th><th class="text-end">Visible</th>' +
                    '</tr></thead><tbody></tbody></table>');
                const $tbody = $tabla.find('tbody');
                cuentas.forEach(function (c) {
                    const sistema = c.es_sistema ? ' <span class="badge bg-secondary">Cuenta del sistema</span>' : '';
                    const $fila = $('<tr>').attr('data-id', c.id);
                    $fila.append($('<td>').html(esc(c.nombre) + sistema));
                    $fila.append($('<td>').append(
                        c.es_sistema ? '' : $('<a href="#" class="js-editar-cuenta" title="Editar"><i class="fas fa-pencil-alt"></i></a>')
                    ));
                    $fila.append($('<td>').addClass('text-end').text(c.visible ? 'Sí' : 'No'));
                    $fila.data('cuenta', c);
                    $tbody.append($fila);
                });
                $cont.append($tabla);
            });
        }

        function cargarConfigCuentas() {
            return $.getJSON(rutas.configData).done(function (resp) {
                renderGrupos(resp.data || {});
            }).fail(function () {
                toast('error', 'No se pudo cargar la configuración de cuentas.');
            });
        }

        $('#btn-configurar-cuentas').on('click', function () {
            cargarConfigCuentas();
            modalConfig ? modalConfig.show() : $modalConfig.show();
        });

        // ---- Modal alta/edición de cuenta ----
        const $modalCuenta = $('#modal-cuenta-tesoreria');
        const modalCuenta = window.bootstrap && $modalCuenta.length ? new window.bootstrap.Modal($modalCuenta[0]) : null;
        const $formCuenta = $('#form-cuenta-tesoreria');
        const $tipoSelect = $('#cuenta-tipo');
        initSelect2($tipoSelect, { dropdownParent: $modalCuenta, minimumResultsForSearch: Infinity });

        function limpiarErroresCuenta() {
            $formCuenta.find('.is-invalid').removeClass('is-invalid');
            $formCuenta.find('[data-error]').text('');
        }

        function mostrarErroresCuenta(errors) {
            Object.keys(errors || {}).forEach(function (campo) {
                $formCuenta.find('[name="' + campo + '"]').addClass('is-invalid');
                $formCuenta.find('[data-error="' + campo + '"]').text(errors[campo][0]);
            });
        }

        function abrirModalCuenta(cuenta) {
            $formCuenta[0].reset();
            limpiarErroresCuenta();
            $('#cuenta-sistema-aviso').addClass('d-none');
            $('#btn-eliminar-cuenta').addClass('d-none');
            $('#cuenta-saldo-inicial-fecha').val(new Date().toISOString().slice(0, 10));

            if (cuenta) {
                $('#modal-cuenta-tesoreria-titulo').text('Editar Cuenta');
                $('#cuenta-id').val(cuenta.id);
                $('#cuenta-nombre').val(cuenta.nombre);
                $('#cuenta-saldo-inicial').val(cuenta.saldo_inicial);
                $('#cuenta-saldo-inicial-fecha').val((cuenta.saldo_inicial_fecha || '').slice(0, 10));
                $tipoSelect.val(cuenta.tipo).trigger('change.select2').prop('disabled', true);
                $('#cuenta-visible-wrap').removeClass('d-none');
                $('#cuenta-visible-mostrar, #cuenta-visible-ocultar').prop('checked', false);
                $(cuenta.visible ? '#cuenta-visible-mostrar' : '#cuenta-visible-ocultar').prop('checked', true);
                $('#btn-guardar-cuenta').text('Guardar');

                if (cuenta.es_sistema) {
                    $('#cuenta-sistema-aviso').removeClass('d-none');
                    $formCuenta.find('input, select, textarea, button[type="submit"]').prop('disabled', true);
                    $('#btn-eliminar-cuenta').addClass('d-none');
                } else {
                    $('#btn-eliminar-cuenta').removeClass('d-none');
                }
            } else {
                $('#modal-cuenta-tesoreria-titulo').text('Nueva Cuenta');
                $('#cuenta-id').val('');
                $tipoSelect.prop('disabled', false).val('efectivo').trigger('change.select2');
                $('#cuenta-visible-wrap').addClass('d-none');
                $('#btn-guardar-cuenta').text('Crear');
            }

            // Bootstrap no incrementa el z-index entre modales apilados en este
            // template (quedan ambos al mismo nivel) — se oculta el de config
            // mientras se edita/crea la cuenta, y se reabre al cerrar.
            if (modalConfig) { modalConfig.hide(); }
            modalCuenta ? modalCuenta.show() : $modalCuenta.show();
        }

        $modalCuenta.on('hidden.bs.modal', function () {
            if (modalConfig && $modalConfig.data('reabrir-tras-cuenta')) {
                $modalConfig.removeData('reabrir-tras-cuenta');
                modalConfig.show();
            }
        });

        $('#btn-nueva-cuenta').on('click', function () {
            $modalConfig.data('reabrir-tras-cuenta', true);
            abrirModalCuenta(null);
        });

        $('#config-cuentas-grupos').on('click', '.js-editar-cuenta', function (e) {
            e.preventDefault();
            $modalConfig.data('reabrir-tras-cuenta', true);
            const cuenta = $(this).closest('tr').data('cuenta');
            abrirModalCuenta(cuenta);
        });

        // Clic en el nombre de una cuenta desde Saldos abre su ficha (link normal, no JS).

        $formCuenta.on('submit', function (e) {
            e.preventDefault();
            limpiarErroresCuenta();

            const id = $('#cuenta-id').val();
            const datos = {
                nombre: $('#cuenta-nombre').val(),
                tipo: $tipoSelect.val(),
                saldo_inicial: $('#cuenta-saldo-inicial').val() || 0,
                saldo_inicial_fecha: $('#cuenta-saldo-inicial-fecha').val(),
            };

            let promesa;
            if (id) {
                datos.visible = $('#cuenta-visible-mostrar').is(':checked') ? 1 : 0;
                promesa = $.ajax({ url: rutas.cuentasBase + '/' + id, method: 'POST', dataType: 'json', data: Object.assign({ _method: 'PUT' }, datos) });
            } else {
                promesa = $.ajax({ url: rutas.cuentasStore, method: 'POST', dataType: 'json', data: datos });
            }

            window.AppBtn.loading('#btn-guardar-cuenta', true);
            promesa.done(function (resp) {
                toast('success', resp.mensaje);
                modalCuenta ? modalCuenta.hide() : $modalCuenta.hide();
                cargarConfigCuentas();
                if (window.TesoreriaSaldos) { window.TesoreriaSaldos.recargar(window.TesoreriaSaldos.fechaActual()); }
            }).fail(function (xhr) {
                const resp = xhr.responseJSON || {};
                if (resp.errors) { mostrarErroresCuenta(resp.errors); }
                toast('error', resp.message || resp.mensaje || 'No se pudo guardar la cuenta.');
            }).always(function () {
                window.AppBtn.loading('#btn-guardar-cuenta', false);
            });
        });

        $('#btn-eliminar-cuenta').on('click', function () {
            const id = $('#cuenta-id').val();
            if (!id || !window.confirm('¿Eliminar esta cuenta?')) { return; }

            $.ajax({ url: rutas.cuentasBase + '/' + id, method: 'POST', dataType: 'json', data: { _method: 'DELETE' } })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    modalCuenta ? modalCuenta.hide() : $modalCuenta.hide();
                    cargarConfigCuentas();
                    if (window.TesoreriaSaldos) { window.TesoreriaSaldos.recargar(window.TesoreriaSaldos.fechaActual()); }
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo eliminar la cuenta.');
                });
        });

        // ============================================================
        // Movimiento entre Cuentas (US3)
        // ============================================================
        const $modalTransf = $('#modal-transferencia');
        const modalTransf = window.bootstrap && $modalTransf.length ? new window.bootstrap.Modal($modalTransf[0]) : null;
        const $formTransf = $('#form-transferencia');

        function etiquetaCuenta(c) {
            return c.nombre + ' — ' + fmtMoney(c.saldo);
        }

        function initSelectCuenta($el) {
            initSelect2($el, {
                dropdownParent: $modalTransf,
                placeholder: 'Elija una cuenta',
                minimumInputLength: 0,
                ajax: {
                    url: rutas.cuentasOpciones,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { q: params.term || '' }; },
                    processResults: function (data) {
                        return { results: (data.data || []).map(function (c) { return { id: c.id, text: etiquetaCuenta(c) }; }) };
                    },
                },
            });
        }

        if ($modalTransf.length) {
            initSelectCuenta($('#transferencia-cuenta-salida'));
            initSelectCuenta($('#transferencia-cuenta-entrada'));
        }

        function abrirModalTransferencia() {
            $formTransf[0].reset();
            $formTransf.find('.is-invalid').removeClass('is-invalid');
            $formTransf.find('[data-error]').text('');
            $('#transferencia-fecha').val(new Date().toISOString().slice(0, 10));
            $('#transferencia-cuenta-salida, #transferencia-cuenta-entrada').val(null).trigger('change');
            modalTransf ? modalTransf.show() : $modalTransf.show();
        }

        $('#btn-movimiento-entre-cuentas').on('click', abrirModalTransferencia);
        $(document).on('click', '.js-movimiento-entre-cuentas', abrirModalTransferencia);

        $formTransf.on('submit', function (e) {
            e.preventDefault();
            $formTransf.find('.is-invalid').removeClass('is-invalid');
            $formTransf.find('[data-error]').text('');

            const datos = {
                fecha: $('#transferencia-fecha').val(),
                monto: $('#transferencia-monto').val(),
                cuenta_salida_id: $('#transferencia-cuenta-salida').val(),
                cuenta_entrada_id: $('#transferencia-cuenta-entrada').val(),
                observacion: $('#transferencia-observacion').val(),
            };

            window.AppBtn.loading($formTransf.find('button[type="submit"]'), true);
            $.ajax({ url: rutas.transferenciasStore, method: 'POST', dataType: 'json', data: datos })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                    modalTransf ? modalTransf.hide() : $modalTransf.hide();
                    if (window.TesoreriaSaldos) { window.TesoreriaSaldos.recargar(window.TesoreriaSaldos.fechaActual()); }
                    if (window.TesoreriaLedger) { window.TesoreriaLedger.recargar(); }
                })
                .fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    if (resp.errors) {
                        Object.keys(resp.errors).forEach(function (campo) {
                            $formTransf.find('[name="' + campo + '"]').addClass('is-invalid');
                            $formTransf.find('[data-error="' + campo + '"]').text(resp.errors[campo][0]);
                        });
                    }
                    toast('error', resp.message || 'No se pudo registrar el movimiento.');
                })
                .always(function () {
                    window.AppBtn.loading($formTransf.find('button[type="submit"]'), false);
                });
        });

        // ============================================================
        // Ficha/ledger de cuenta (US4)
        // ============================================================
        const $tablaLedger = $('#tabla-ledger');
        if ($tablaLedger.length) {
            const ETIQUETAS_OPERACION = {
                saldo_inicial: 'Saldo Inicial',
                movimiento_entre_cuentas: 'Movimiento entre Cuenta',
                cobro: 'Cobro',
                pago: 'Pago',
                gasto: 'Gasto',
            };

            function fmtFecha(val) {
                return val ? String(val).slice(0, 10).split('-').reverse().join('/') : '';
            }

            const tablaLedger = $tablaLedger.DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                language: {
                    lengthMenu: 'Mostrar _MENU_ registros',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ movimientos',
                    infoEmpty: 'Sin movimientos',
                    infoFiltered: '(filtrado de _MAX_ en total)',
                    zeroRecords: 'No se encontraron movimientos',
                    paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                    processing: 'Cargando...',
                },
                ajax: {
                    url: rutas.ledgerData,
                    data: function (d) {
                        d.tipo_operacion = $('#filtro-tipo-operacion').val();
                        d.desde = $('#filtro-ledger-desde').val();
                        d.hasta = $('#filtro-ledger-hasta').val();
                    },
                },
                columns: [
                    { data: 'id', name: 'id', className: 'text-end' },
                    { data: 'fecha', name: 'fecha', render: fmtFecha },
                    { data: 'operacion', name: 'tipo' },
                    { data: 'detalle', name: 'detalle', defaultContent: '' },
                    {
                        data: 'ingreso', name: 'monto', className: 'text-end',
                        render: function (val) { return val ? fmtMoney(val) : ''; },
                    },
                    {
                        data: 'egreso', name: 'monto', className: 'text-end',
                        render: function (val) { return val ? fmtMoney(val) : ''; },
                    },
                    {
                        data: 'balance', name: 'balance', className: 'text-end fw-bold',
                        render: function (val) { return '<span class="bg-warning-light px-1">' + fmtMoney(val) + '</span>'; },
                    },
                    { data: 'nro_comprobante', name: 'nro_comprobante', defaultContent: '' },
                    { data: 'observacion', name: 'observacion', defaultContent: '' },
                    { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'text-end' },
                ],
                order: [[1, 'asc'], [0, 'asc']],
                // Selector de columnas nativo de DataTables (extensión Buttons) +
                // stateSave: persiste qué columnas quedaron ocultas en localStorage.
                stateSave: true,
                buttons: [
                    {
                        extend: 'colvis',
                        text: '<i class="fas fa-table-columns"></i>',
                        className: 'btn btn-outline-secondary',
                        // Última columna ("Acciones") no se puede ocultar.
                        columns: function (idx) { return idx !== tablaLedger.columns().count() - 1; },
                    },
                ],
            });

            // serverSide:true => la tabla recién termina de inicializarse (y los
            // Buttons quedan listos) cuando responde el primer AJAX.
            $tablaLedger.one('init.dt', function () {
                tablaLedger.buttons().container().appendTo('#dt-buttons-tesoreria-ledger');
            });

            window.TesoreriaLedger = { recargar: function () { tablaLedger.ajax.reload(); } };

            $('#filtro-tipo-operacion, #filtro-ledger-desde, #filtro-ledger-hasta').on('change', function () {
                tablaLedger.ajax.reload();
            });

            // --- Menú de fila: Editar / Eliminar (sólo nativos se editan/eliminan íntegramente) ---
            const $modalMovEditar = $('#modal-movimiento-editar');
            const modalMovEditar = window.bootstrap && $modalMovEditar.length ? new window.bootstrap.Modal($modalMovEditar[0]) : null;
            const $formMovEditar = $('#form-movimiento-editar');

            $tablaLedger.on('click', '.js-movimiento-editar', function (e) {
                e.preventDefault();
                const fila = tablaLedger.row($(this).closest('tr')).data();
                if (!fila) { return; }
                if (!fila.es_nativo) {
                    toast('error', 'Este movimiento se originó en otro módulo y no se edita desde Tesorería.');
                    return;
                }
                $formMovEditar[0].reset();
                $formMovEditar.find('.is-invalid').removeClass('is-invalid');
                $('#movimiento-editar-id').val(fila.id);
                $('#movimiento-editar-fecha').val((fila.fecha || '').slice(0, 10));
                $('#movimiento-editar-monto').val(fila.monto);
                $('#movimiento-editar-observacion').val(fila.observacion);
                modalMovEditar ? modalMovEditar.show() : $modalMovEditar.show();
            });

            $formMovEditar.on('submit', function (e) {
                e.preventDefault();
                const id = $('#movimiento-editar-id').val();
                const datos = {
                    fecha: $('#movimiento-editar-fecha').val(),
                    monto: $('#movimiento-editar-monto').val(),
                    observacion: $('#movimiento-editar-observacion').val(),
                };
                $.ajax({ url: rutas.movimientosBase + '/' + id, method: 'POST', dataType: 'json', data: Object.assign({ _method: 'PUT' }, datos) })
                    .done(function (resp) {
                        toast('success', resp.mensaje);
                        modalMovEditar ? modalMovEditar.hide() : $modalMovEditar.hide();
                        tablaLedger.ajax.reload(null, false);
                    })
                    .fail(function (xhr) {
                        const resp = xhr.responseJSON || {};
                        toast('error', resp.mensaje || 'No se pudo actualizar el movimiento.');
                    });
            });

            $tablaLedger.on('click', '.js-movimiento-eliminar', function (e) {
                e.preventDefault();
                const id = $(this).data('id');
                if (!window.confirm('¿Eliminar este movimiento?')) { return; }

                $.ajax({ url: rutas.movimientosBase + '/' + id, method: 'POST', dataType: 'json', data: { _method: 'DELETE' } })
                    .done(function (resp) {
                        toast('success', resp.mensaje);
                        tablaLedger.ajax.reload(null, false);
                        if (window.TesoreriaSaldos) { window.TesoreriaSaldos.recargar(window.TesoreriaSaldos.fechaActual()); }
                    })
                    .fail(function (xhr) {
                        const resp = xhr.responseJSON || {};
                        toast('error', resp.mensaje || 'No se pudo eliminar el movimiento.');
                    });
            });

            $('#btn-exportar-ledger').on('click', function (e) {
                e.preventDefault();
                const params = new URLSearchParams();
                const tipoOperacion = $('#filtro-tipo-operacion').val();
                if (tipoOperacion) { params.set('tipo_operacion', tipoOperacion); }
                window.location = rutas.ledgerExport + '?' + params.toString();
                toast('info', 'Generando la exportación...');
            });
        }

        // ============================================================
        // Informe Movimientos (US5)
        // ============================================================
        const $tablaCobros = $('#tabla-desglose-cobros tbody');
        if ($tablaCobros.length) {
            function renderDesglose($tbody, filas) {
                $tbody.empty();
                (filas || []).forEach(function (f) {
                    $tbody.append(
                        $('<tr>').append(
                            $('<td>').append(
                                $('<input type="checkbox" class="form-check-input js-cuenta-activa" checked>')
                                    .attr('data-cuenta-id', f.cuenta_id).attr('data-monto', f.monto)
                            ),
                            $('<td>').text(f.nombre),
                            $('<td>').addClass('text-end').text(fmtMoney(f.monto))
                        )
                    );
                });
            }

            function recalcularTotales() {
                let cobros = 0;
                $('#tabla-desglose-cobros .js-cuenta-activa:checked').each(function () {
                    cobros += parseFloat($(this).data('monto')) || 0;
                });
                let pagos = 0;
                $('#tabla-desglose-pagos .js-cuenta-activa:checked').each(function () {
                    pagos += parseFloat($(this).data('monto')) || 0;
                });

                $('#resumen-total-cobros, #seccion-cobros-total').text(fmtMoney(cobros));
                $('#resumen-total-pagos, #seccion-pagos-total').text(fmtMoney(pagos));
                $('#resumen-resultado').text(fmtMoney(cobros - pagos));
            }

            function cargarMovimientos() {
                $.getJSON(rutas.movimientosData, {
                    desde: $('#movimientos-desde').val(),
                    hasta: $('#movimientos-hasta').val(),
                }).done(function (resp) {
                    renderDesglose($tablaCobros, resp.cobros);
                    renderDesglose($('#tabla-desglose-pagos tbody'), resp.pagos);
                    recalcularTotales();
                }).fail(function () {
                    toast('error', 'No se pudo cargar el informe de Movimientos.');
                });
            }

            cargarMovimientos();
            $('#movimientos-desde, #movimientos-hasta').on('change', cargarMovimientos);
            $(document).on('change', '.js-cuenta-activa', recalcularTotales);

            function parametrosExport() {
                const params = new URLSearchParams();
                params.set('desde', $('#movimientos-desde').val());
                params.set('hasta', $('#movimientos-hasta').val());
                $('.js-cuenta-activa:checked').each(function () {
                    params.append('cuentas_activas[]', $(this).data('cuenta-id'));
                });
                return params;
            }

            $('#btn-exportar-movimientos').on('click', function () {
                window.location = rutas.movimientosExport + '?' + parametrosExport().toString();
                toast('info', 'Generando la exportación...');
            });

            $('#btn-exportar-movimientos-pdf').on('click', function () {
                const url = rutas.movimientosPdf + '?' + parametrosExport().toString();
                if (window.AppPdf) {
                    window.AppPdf.abrir(url, 'Informe Movimientos de Tesorería');
                } else {
                    window.open(url, '_blank');
                }
            });
        }
    });
})();
