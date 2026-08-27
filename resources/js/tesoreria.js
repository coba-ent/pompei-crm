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
                    // Las dos filas sintéticas de cuenta corriente no son cuentas de tesorería
                    // (vienen sin `id`, ver Tesoreria::saldos()), así que enlazan a su informe en
                    // vez de a la ficha de una cuenta.
                    const informeCtaCte = {
                        'Saldo Cta Cte Clientes': rutas.cuentaCorrienteClientes,
                        'Saldo Cta Cte Proveedores': rutas.cuentaCorrienteProveedores,
                    }[c.nombre];

                    let $nombre;
                    if (c.id) {
                        $nombre = $('<a>').attr('href', rutas.cuentasBase + '/' + c.id).text(c.nombre);
                    } else if (informeCtaCte) {
                        $nombre = $('<a>').attr('href', informeCtaCte).text(c.nombre);
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

            // El input muestra dd/mm/yyyy (formato argentino) y guarda el ISO en `data-fecha`,
            // que es lo único que viaja al backend. Ver el comentario de la vista.
            const $fechaCorte = $('#tesoreria-fecha-corte');

            function fechaISO() {
                return $fechaCorte.data('fecha');
            }

            if ($.fn.datepicker) {
                $fechaCorte.datepicker({
                    format: 'dd/mm/yyyy',
                    autoclose: true,
                    todayHighlight: true,
                    weekStart: 1, // el template no trae el locale `es` de bootstrap-datepicker
                }).on('changeDate', function (e) {
                    const d = e.date;
                    const iso = d.getFullYear() + '-' +
                        String(d.getMonth() + 1).padStart(2, '0') + '-' +
                        String(d.getDate()).padStart(2, '0');
                    $fechaCorte.data('fecha', iso);
                    cargarSaldos(iso);
                });
            } else {
                // Sin datepicker el campo sigue siendo usable a mano en dd/mm/yyyy.
                $fechaCorte.on('change', function () {
                    const p = String($(this).val()).split('/');
                    if (p.length !== 3) { return; }
                    const iso = p[2] + '-' + p[1].padStart(2, '0') + '-' + p[0].padStart(2, '0');
                    $fechaCorte.data('fecha', iso);
                    cargarSaldos(iso);
                });
            }

            if (cfg.saldosIniciales) {
                renderSaldos(cfg.saldosIniciales);
            } else {
                cargarSaldos(fechaISO());
            }

            // `pintar` deja repintar las cards con un payload ya recibido (el que devuelve
            // el reordenamiento de cuentas) sin gastar un segundo request.
            window.TesoreriaSaldos = { recargar: cargarSaldos, fechaActual: fechaISO, pintar: renderSaldos };
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
                    '<th class="cuenta-handle-col"></th>' +
                    '<th>' + ETIQUETAS_TIPO[tipo] + '</th><th></th><th class="text-end">Visible</th>' +
                    '</tr></thead><tbody></tbody></table>');
                const $tbody = $tabla.find('tbody').attr('data-tipo', tipo);
                cuentas.forEach(function (c) {
                    const sistema = c.es_sistema ? ' <span class="badge bg-secondary">Cuenta del sistema</span>' : '';
                    const $fila = $('<tr>').attr('data-id', c.id);
                    // El handle va primero: es el asidero del drag y el que recibe el foco
                    // para mover con ArrowUp/ArrowDown. Las cuentas del sistema también se
                    // reordenan (el badge sólo bloquea la edición, no la posición).
                    $fila.append($('<td>').addClass('cuenta-handle-col').append(
                        $('<button type="button" class="js-mover-cuenta"><i class="fas fa-grip-vertical"></i></button>')
                            .attr('aria-label', 'Reordenar ' + c.nombre)
                    ));
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
            initSortableCuentas();
        }

        // ============================================================
        // Reordenamiento de cuentas por bloque (spec 085)
        // ============================================================

        // Un request en vuelo por tipo: si el usuario encadena arrastres, el anterior se
        // aborta para que no llegue tarde y termine pisando el orden más nuevo (FR-015).
        const requestsOrden = {};

        /**
         * Ids de las filas reales del bloque, en el orden en que están en el DOM.
         *
         * Filtra explícitamente el placeholder y el helper de jQuery UI: mientras dura
         * el arrastre los dos viven dentro del mismo <tbody>, y el helper además es un
         * CLON de la fila levantada, así que sin este filtro la lista sale con un id
         * repetido y un `null` (el placeholder, que no tiene data-id). Esa lista corrupta
         * es la que después se compara contra el orden nuevo, y hacía que el guardado se
         * salteara en silencio.
         */
        function idsDe($tbody) {
            return $tbody.children('tr')
                .not('.cuenta-orden-placeholder, .ui-sortable-helper, .ui-sortable-placeholder')
                .map(function () {
                    return parseInt($(this).attr('data-id'), 10);
                })
                .get()
                .filter(function (id, i, arr) {
                    // Dedup: el helper es un clon de la fila levantada, y en algunos
                    // momentos del ciclo de vida todavía no tiene la clase que lo delata.
                    return !isNaN(id) && arr.indexOf(id) === i;
                });
        }

        function initSortableCuentas() {
            if (!$.fn.sortable) {
                console.error('[tesoreria] jQuery UI no está disponible: no se puede reordenar cuentas.');
                return;
            }
            $('#config-cuentas-grupos tbody[data-tipo]').each(function () {
                const $tbody = $(this);
                // Con una sola cuenta no hay nada que reordenar.
                if ($tbody.children('tr').length < 2) { return; }
                $tbody.sortable({
                    handle: 'button.js-mover-cuenta',
                    // jQuery UI trae `cancel: "input,textarea,button,select,option"`, o sea que
                    // por defecto se NIEGA a iniciar un arrastre desde un <button> — y el handle
                    // justamente lo es, porque tiene que ser focusable para moverse con el
                    // teclado (FR-013). Sin este override el mousedown se descarta y el drag no
                    // arranca nunca. `a` mantiene fuera el lápiz de editar.
                    cancel: 'a',
                    items: '> tr',
                    axis: 'y',
                    containment: 'parent',
                    placeholder: 'cuenta-orden-placeholder',
                    // NO se usa `connectWith`, y esa ausencia es lo que hace imposible
                    // arrastrar una fila de un bloque a otro — o sea, lo que impide que un
                    // reordenamiento cambie el `tipo` de una cuenta (FR-003). El servidor
                    // lo rechaza igual, pero acá ni siquiera se llega a intentar.
                    helper: function (e, $fila) {
                        // Al levantar el <tr> de la tabla sus <td> pierden el ancho que les
                        // daba el layout: se fija para que la fila arrastrada no se deforme.
                        const $clon = $fila.clone();
                        $clon.children('td').each(function (i) {
                            $(this).width($fila.children('td').eq(i).width());
                        });
                        return $clon;
                    },
                    start: function (e, ui) {
                        // El orden previo NO se captura acá: para cuando corre `start`,
                        // sortable ya insertó el helper (un clon de la fila levantada) y
                        // el placeholder dentro del <tbody>. Se captura en el `mousedown`
                        // del handle, más abajo, que es el último momento en que el
                        // <tbody> tiene exactamente las filas reales.
                        ui.placeholder.height(ui.item.height());
                    },
                    update: function () {
                        // El setTimeout no es cosmético: cuando corre `update`, sortable
                        // todavía tiene el helper (el clon de la fila) dentro del <tbody>,
                        // así que leer el orden en este momento devuelve una lista que aún
                        // no es la definitiva. Se difiere un tick para leer el DOM ya
                        // limpio, que es el orden que hay que guardar.
                        setTimeout(function () { persistirOrden($tbody); }, 0);
                    },
                });
            });
        }

        function persistirOrden($tbody) {
            const tipo = $tbody.attr('data-tipo');
            const previo = $tbody.data('orden-previo') || [];
            const ids = idsDe($tbody);

            // El arrastre terminó donde empezó: nada que guardar ni que avisar (FR-005).
            if (previo.length === ids.length && previo.every(function (id, i) { return id === ids[i]; })) {
                return;
            }

            if (requestsOrden[tipo]) { requestsOrden[tipo].abort(); }

            requestsOrden[tipo] = $.ajax({
                url: rutas.cuentasOrden,
                method: 'PATCH',
                dataType: 'json',
                data: { tipo: tipo, ids: ids },
            }).done(function (resp) {
                toast('success', (resp && resp.mensaje) || 'Orden actualizado con éxito.');
                // El orden nuevo ya está en el DOM del modal; falta repintar las cards de
                // fondo sin recargar la página (FR-010). El `saldos` de la respuesta viene
                // calculado a hoy: si el usuario tiene otra fecha de corte no sirve y hay
                // que volver a pedirlos.
                if (window.TesoreriaSaldos) {
                    const fecha = window.TesoreriaSaldos.fechaActual();
                    const hoy = window.AppFecha ? window.AppFecha.hoy() : null;
                    if (resp && resp.saldos && fecha === hoy) {
                        window.TesoreriaSaldos.pintar(resp.saldos);
                    } else {
                        window.TesoreriaSaldos.recargar(fecha);
                    }
                }
            }).fail(function (xhr) {
                // Reemplazado por un arrastre posterior: el que vale es el otro.
                if (xhr.statusText === 'abort') { return; }

                if (xhr.status === 409) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.mensaje) ||
                        'El listado de cuentas cambió mientras reordenabas.';
                    toast('error', msg);
                    cargarConfigCuentas(); // trae el estado real, no un revert a algo viejo
                    return;
                }

                toast('error', 'No se pudo guardar el orden de las cuentas.');
                revertirOrden($tbody, previo);
            }).always(function () {
                delete requestsOrden[tipo];
            });
        }

        /** Devuelve las filas al orden que tenían antes del arrastre (FR-009). */
        function revertirOrden($tbody, previo) {
            if (!previo || !previo.length) { return; }
            previo.forEach(function (id) {
                const $fila = $tbody.children('tr[data-id="' + id + '"]');
                if ($fila.length) { $tbody.append($fila); }
            });
        }

        // El orden previo se congela al apretar el mouse sobre el handle, antes de que
        // jQuery UI meta el helper y el placeholder en el <tbody>. Es lo que después se
        // compara para decidir si hubo cambio real (FR-005) y para revertir si falla.
        $('#config-cuentas-grupos').on('mousedown', 'button.js-mover-cuenta', function () {
            const $tbody = $(this).closest('tbody[data-tipo]');
            $tbody.data('orden-previo', idsDe($tbody));
        });

        // Alternativa por teclado al arrastre (FR-013): con el foco en el handle,
        // ArrowUp/ArrowDown mueven la fila una posición. Guarda por la MISMA
        // persistirOrden() que el drag, para que no existan dos caminos de guardado.
        $('#config-cuentas-grupos').on('keydown', 'button.js-mover-cuenta', function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') { return; }
            e.preventDefault();

            const $fila = $(this).closest('tr');
            const $tbody = $fila.closest('tbody[data-tipo]');
            const $vecina = e.key === 'ArrowUp' ? $fila.prev('tr') : $fila.next('tr');

            // Ya está en el extremo del bloque: no se sale ni dispara request.
            if (!$vecina.length) { return; }

            $tbody.data('orden-previo', idsDe($tbody));

            if (e.key === 'ArrowUp') {
                $vecina.before($fila);
            } else {
                $vecina.after($fila);
            }

            // Mover el nodo lo saca del DOM y con él se va el foco: hay que devolverlo
            // para poder encadenar varias pulsaciones sin volver a tabular.
            $fila.find('button.js-mover-cuenta').trigger('focus');

            persistirOrden($tbody);
        });

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
            AppFecha.set($('#cuenta-saldo-inicial-fecha'), AppFecha.hoy());

            if (cuenta) {
                $('#modal-cuenta-tesoreria-titulo').text('Editar Cuenta');
                $('#cuenta-id').val(cuenta.id);
                $('#cuenta-nombre').val(cuenta.nombre);
                // Editar cambia nombre y visibilidad, nada más: el saldo inicial y su fecha
                // son datos de apertura y no se retocan. Se saca el `required` del input al
                // ocultarlo, si no el navegador bloquea el submit sobre un campo invisible.
                $('#cuenta-apertura-wrap').addClass('d-none');
                $('#cuenta-saldo-inicial-fecha').prop('required', false);
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
                $('#cuenta-apertura-wrap').removeClass('d-none');
                $('#cuenta-saldo-inicial-fecha').prop('required', true);
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

            let promesa;
            if (id) {
                // Editar manda SÓLO nombre y visibilidad. El tipo es inmutable, y el saldo
                // inicial y su fecha son datos de apertura: mandarlos hacía que el backend
                // reescribiera el movimiento de Saldo Inicial y moviera el saldo de la cuenta.
                const datos = {
                    nombre: $('#cuenta-nombre').val(),
                    visible: $('#cuenta-visible-mostrar').is(':checked') ? 1 : 0,
                };
                promesa = $.ajax({ url: rutas.cuentasBase + '/' + id, method: 'POST', dataType: 'json', data: Object.assign({ _method: 'PUT' }, datos) });
            } else {
                const datos = {
                    nombre: $('#cuenta-nombre').val(),
                    tipo: $tipoSelect.val(),
                    saldo_inicial: $('#cuenta-saldo-inicial').val() || 0,
                    saldo_inicial_fecha: AppFecha.get($('#cuenta-saldo-inicial-fecha')),
                };
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
            AppFecha.set($('#transferencia-fecha'), AppFecha.hoy());
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
                fecha: AppFecha.get($('#transferencia-fecha')),
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
                        d.desde = AppFecha.get($('#filtro-ledger-desde'));
                        d.hasta = AppFecha.get($('#filtro-ledger-hasta'));
                    },
                },
                columns: [
                    { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'dt-acciones-caret no-colvis' },
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
                ],
                order: [[2, 'desc'], [1, 'desc']],
                // Selector de columnas nativo de DataTables (extensión Buttons) +
                // stateSave: persiste qué columnas quedaron ocultas en localStorage.
                stateSave: true,
                buttons: [
                    {
                        extend: 'colvis',
                        text: '<i class="fas fa-table-columns"></i>',
                        className: 'btn btn-outline-secondary',
                        columns: ':not(.no-colvis)',
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

            // Delegado en document (no en $tablaLedger): dropdown-escape-scroll.js
            // reparenta el .dropdown-menu a <body> al abrirse, así que al
            // momento del click el botón ya no es descendiente de la tabla
            // ni de su <tr> — se busca la fila por id en vez de con closest('tr').
            $(document).on('click', '.js-movimiento-editar', function (e) {
                e.preventDefault();
                const id = $(this).data('id');
                const fila = tablaLedger.rows().data().toArray().find(function (row) { return row.id == id; });
                if (!fila) { return; }
                if (!fila.es_nativo) {
                    toast('error', 'Este movimiento se originó en otro módulo y no se edita desde Tesorería.');
                    return;
                }
                $formMovEditar[0].reset();
                $formMovEditar.find('.is-invalid').removeClass('is-invalid');
                $('#movimiento-editar-id').val(fila.id);
                AppFecha.set($('#movimiento-editar-fecha'), fila.fecha);
                $('#movimiento-editar-monto').val(fila.monto);
                $('#movimiento-editar-observacion').val(fila.observacion);
                modalMovEditar ? modalMovEditar.show() : $modalMovEditar.show();
            });

            $formMovEditar.on('submit', function (e) {
                e.preventDefault();
                const id = $('#movimiento-editar-id').val();
                const datos = {
                    fecha: AppFecha.get($('#movimiento-editar-fecha')),
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

            $(document).on('click', '.js-movimiento-eliminar', function (e) {
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
                    desde: AppFecha.get($('#movimientos-desde')),
                    hasta: AppFecha.get($('#movimientos-hasta')),
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
                params.set('desde', AppFecha.get($('#movimientos-desde')) || '');
                params.set('hasta', AppFecha.get($('#movimientos-hasta')) || '');
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
