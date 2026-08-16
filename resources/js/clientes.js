/**
 * Módulo Clientes — DataTable server-side + modales AJAX + toasts.
 *
 * Usa jQuery, Toastr, DataTables y DataTables Buttons/ColVis cargados globalmente
 * por el template NexaDash (config/dz.php pagelevel 'clientes'). No importa
 * dependencias: referencia los globales para no duplicar el bundle del template.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[clientes] jQuery no está disponible.');
        return;
    }

    const cfg = window.ClientesConfig || {};
    const rutas = cfg.rutas || {};

    // Configuración global reutilizable de Toastr.
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
            console.log('[clientes][' + tipo + ']', mensaje);
        }
    }

    function esc(v) {
        return (v || v === 0) ? $('<div>').text(v).html() : '';
    }

    /** Matchea por texto (case/acentos-insensitive) contra las <option> de un <select>; devuelve el value si matchea. */
    function normalizarTexto(txt) {
        return (txt || '').toString().trim().toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function buscarOpcionPorTexto($select, valor) {
        if (!valor) { return null; }
        const buscado = normalizarTexto(valor);
        const $opcion = $select.find('option').filter(function () {
            return normalizarTexto($(this).text()) === buscado || normalizarTexto($(this).val()) === buscado;
        });
        return $opcion.length ? $opcion.first().val() : null;
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    $(function () {
        const $tabla = $('#tabla-clientes');
        if (!$tabla.length) {
            return;
        }

        // --- DataTable server-side + ColVis con persistencia en navegador ---
        // El buscador propio de DataTables (f) se reemplaza por el buscador estilo
        // Contagram (input + botón). El botón ColVis se crea DESPUÉS de instanciar
        // la tabla (si se pone 'B' en el dom, Buttons se inicializa antes de que
        // existan las columnas y no se renderiza).
        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            // Recordar visibilidad de columnas / orden / búsqueda en localStorage.
            stateSave: true,
            stateDuration: 0, // 0 = indefinido (localStorage), persiste entre sesiones.
            dom:
                "<'row mb-2 align-items-center'<'col-sm-6'l>>" +
                "<'row'<'col-sm-12'tr>>" +
                "<'row mt-2 align-items-center'<'col-sm-5'i><'col-sm-7'p>>",
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ clientes',
                infoEmpty: 'Sin clientes',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron clientes',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
                buttons: { colvis: 'Columnas' },
            },
            ajax: {
                url: rutas.data,
            },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'dt-acciones-caret no-colvis' },
                { data: 'id', name: 'id' },
                { data: 'nombre', name: 'nombre' },
                { data: 'nombre_pila', name: 'nombre_pila', defaultContent: '' },
                { data: 'apellido', name: 'apellido', defaultContent: '' },
                { data: 'email', name: 'email', defaultContent: '' },
                { data: 'telefono', name: 'telefono', defaultContent: '' },
                { data: 'telefono_celular', name: 'telefono_celular', defaultContent: '' },
                { data: 'domicilio', name: 'domicilio', defaultContent: '' },
                { data: 'localidad', name: 'localidad', defaultContent: '' },
                { data: 'provincia', name: 'provincia', defaultContent: '' },
                { data: 'doc_dni', name: 'doc_dni', orderable: false, searchable: false, defaultContent: '' },
                { data: 'doc_cuit', name: 'doc_cuit', orderable: false, searchable: false, defaultContent: '' },
                { data: 'condicion_iva', name: 'condicion_iva', orderable: false },
                { data: 'apodo_ml', name: 'apodo_ml', defaultContent: '' },
                { data: 'nota', name: 'nota', defaultContent: '' },
                { data: 'pagina_web', name: 'pagina_web', defaultContent: '' },
            ],
            order: [[2, 'asc']],
        });

        // Botón ColVis ("Columnas") creado DESPUÉS de la tabla y anexado al toolbar.
        // La visibilidad de columnas queda persistida por stateSave (localStorage).
        if ($.fn.dataTable && $.fn.dataTable.Buttons) {
            new $.fn.dataTable.Buttons(tabla, {
                buttons: [
                    {
                        extend: 'colvis',
                        text: '<i class="fas fa-columns me-1"></i> Columnas',
                        className: 'btn btn-outline-secondary',
                        columns: ':not(.no-colvis)',
                    },
                ],
            });
            tabla.buttons().container().appendTo('#clientes-colvis');
        }

        // --- Buscador estilo Contagram (busca por cualquier dato, server-side) ---
        // Reflejar la búsqueda persistida (stateSave) en el input al cargar.
        $('#buscador-clientes').val(tabla.search() || '');

        function ejecutarBusqueda() {
            tabla.search($('#buscador-clientes').val() || '').draw();
        }
        $('#btn-buscar-clientes').on('click', ejecutarBusqueda);
        $('#buscador-clientes').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                ejecutarBusqueda();
            }
        });

        // Refrescar las cards informativas sin recargar la página.
        function refrescarStats() {
            if (!rutas.stats) {
                return;
            }
            $.getJSON(rutas.stats).done(function (s) {
                const fmt = function (n) {
                    return new Intl.NumberFormat('es-AR').format(n);
                };
                $('#stat-total').text(fmt(s.total));
                $('#stat-activos').text(fmt(s.activos));
                $('#stat-aptos').text(fmt(s.aptos));
                $('#stat-nuevos').text(fmt(s.nuevos_mes));
            });
        }

        // --- Modal de alta/edición (US1) ---
        const $modal = $('#modal-cliente');
        const modal = window.bootstrap ? new window.bootstrap.Modal($modal[0]) : null;
        const $form = $('#form-cliente');

        function limpiarErrores() {
            $form.find('.is-invalid, .is-valid').removeClass('is-invalid is-valid');
            $form.find('.invalid-feedback').removeClass('text-success').text('');
        }

        // Modo sólo lectura (acción "Ver").
        function setSoloLectura(activo) {
            $form.find('input, select, textarea').prop('disabled', activo);
            $form.find('button').not('[data-bs-dismiss="modal"]').prop('disabled', activo);
            $('#btn-guardar-cliente').toggleClass('d-none', activo);
        }

        function resetForm() {
            $form[0].reset();
            $('#cliente-id').val('');
            limpiarErrores();
            setSoloLectura(false);
            $('#contactos-container').empty();
            $('#campos-personalizados-container').empty();
            // Vaciar los selects de localidad (dependen de la provincia).
            $form.find('.js-localidad').html('<option value="">Seleccionar</option>');
            $('#saldo-inicial-wrap').addClass('d-none');
            resetearTocadoPadron();
        }

        // --- Verificación de CUIT/CUIL (US1, spec 014) ---

        // Auto-formatea el N° de Doc con guiones mientras se tipea (FR-003);
        // el backend ya limpia los guiones antes de validar/guardar (research.md R3).
        function formatearDocumento(valor) {
            const digitos = (valor || '').replace(/\D/g, '').slice(0, 11);
            if (digitos.length <= 2) {
                return digitos;
            }
            if (digitos.length <= 10) {
                return digitos.slice(0, 2) + '-' + digitos.slice(2);
            }
            return digitos.slice(0, 2) + '-' + digitos.slice(2, 10) + '-' + digitos.slice(10);
        }

        // Limpia el resultado de una verificación previa (FR-010): nunca debe
        // quedar visible un "válido"/"inválido" que ya no corresponde al valor actual.
        function limpiarResultadoVerificacion() {
            $form.find('input[name="cuit"]').removeClass('is-invalid is-valid');
            $form.find('.invalid-feedback[data-field="cuit"]').removeClass('text-success').text('');
        }

        function pintarResultadoVerificacion(resp) {
            const $input = $form.find('input[name="cuit"]');
            const $fb = $form.find('.invalid-feedback[data-field="cuit"]');
            $input.removeClass('is-invalid is-valid');
            $fb.removeClass('text-success').text('');

            if (!resp || !resp.aplica) {
                return;
            }
            if (resp.valido) {
                $input.addClass('is-valid');
                $fb.addClass('text-success').text('El CUIT/CUIL ingresado es válido.');
            } else {
                $input.addClass('is-invalid');
                $fb.text(resp.mensaje || 'El CUIT ingresado no es válido.');
            }
        }

        $form.on('input', 'input[name="cuit"]', function () {
            const input = this;
            const cursorDigitosAntes = input.value.slice(0, input.selectionStart).replace(/\D/g, '').length;
            input.value = formatearDocumento(input.value);

            let posicion = 0;
            let digitosVistos = 0;
            while (posicion < input.value.length && digitosVistos < cursorDigitosAntes) {
                if (/\d/.test(input.value[posicion])) {
                    digitosVistos++;
                }
                posicion++;
            }
            input.setSelectionRange(posicion, posicion);

            limpiarResultadoVerificacion();
        });

        $form.on('change', 'select[name="tipo_documento"]', limpiarResultadoVerificacion);

        // --- Autocompletado desde el padrón de ARCA (research.md R5, spec 037) ---
        // Campos que el padrón puede completar: sólo se pisan si el usuario no
        // los tocó manualmente desde la última consulta (se resetea al abrir el
        // modal / cambiar de cliente, en resetForm()).
        const CAMPOS_PADRON = ['razon_social', 'domicilio_fiscal', 'provincia_fiscal', 'localidad_fiscal', 'condicion_iva_id', 'tipo_comprobante_defecto'];
        let tocadoPadron = {};

        function resetearTocadoPadron() {
            tocadoPadron = {};
            CAMPOS_PADRON.forEach(function (campo) {
                tocadoPadron[campo] = false;
            });
        }
        resetearTocadoPadron();

        CAMPOS_PADRON.forEach(function (campo) {
            $form.on('input change', '[name="' + campo + '"]', function () {
                tocadoPadron[campo] = true;
            });
        });

        function autocompletarDesdePadron(padron) {
            if (!padron || !padron.encontrado) {
                return;
            }
            if (padron.razon_social && !tocadoPadron.razon_social) {
                $form.find('input[name="razon_social"]').val(padron.razon_social);
            }
            if (padron.domicilio_fiscal && !tocadoPadron.domicilio_fiscal) {
                $form.find('input[name="domicilio_fiscal"]').val(padron.domicilio_fiscal);
            }
            // Provincia y Localidad son selects linkeados (docs §2.1): primero seleccionar la
            // provincia que devuelve el padrón, y recién con eso disparar la carga AJAX de
            // localidades de esa provincia para poder seleccionar la localidad devuelta.
            if (padron.provincia_fiscal && !tocadoPadron.provincia_fiscal) {
                const $provincia = $form.find('select[name="provincia_fiscal"]');
                const valorProvincia = buscarOpcionPorTexto($provincia, padron.provincia_fiscal);
                if (valorProvincia !== null) {
                    $provincia.val(valorProvincia);
                    const $loc = $form.find('select[name="localidad_fiscal"]');
                    if (!tocadoPadron.localidad_fiscal) {
                        cargarLocalidades($loc, valorProvincia, padron.localidad_fiscal || null);
                    }
                }
            }
            if (padron.condicion_iva && !tocadoPadron.condicion_iva_id) {
                const $select = $form.find('select[name="condicion_iva_id"]');
                const $opcion = $select.find('option').filter(function () {
                    return $(this).text().trim() === padron.condicion_iva;
                });
                if ($opcion.length) {
                    $select.val($opcion.val()).trigger('change');
                }
            }
        }

        // Deriva Factura A/B en "Comprobante por defecto" según el texto de la Condición de
        // IVA elegida (spec 048), mismo criterio que ResolutorCliente/DerivadorComprobante.
        function derivarComprobantePorCondicionIva() {
            if (tocadoPadron.tipo_comprobante_defecto) {
                return;
            }
            const $condicion = $form.find('select[name="condicion_iva_id"]');
            const texto = $condicion.find('option:selected').text().trim();
            if (!texto) {
                return;
            }
            $form.find('select[name="tipo_comprobante_defecto"]').val(texto === 'Responsable Inscripto' ? 'A' : 'B');
        }

        $form.on('change', 'select[name="condicion_iva_id"]', derivarComprobantePorCondicionIva);

        function mostrarMensajePadron(padron) {
            if (!padron) {
                return;
            }
            if (!padron.consultado) {
                toast('info', padron.mensaje || 'No se pudo consultar el padrón de ARCA en este momento.');
            } else if (!padron.encontrado) {
                toast('info', padron.mensaje || 'No se encontró el CUIT en el padrón de ARCA.');
            } else {
                toast('success', 'Datos del padrón de ARCA cargados.');
            }
        }

        let verificacionEnCurso = false;

        $form.on('click', '.js-verificar-documento', function () {
            if (!rutas.verificarDocumento || verificacionEnCurso) {
                return;
            }
            const $boton = $(this);
            verificacionEnCurso = true;
            $boton.prop('disabled', true);

            $.getJSON(rutas.verificarDocumento, {
                tipo_documento: $form.find('select[name="tipo_documento"]').val(),
                numero: $form.find('input[name="cuit"]').val(),
            })
                .done(function (resp) {
                    pintarResultadoVerificacion(resp);
                    if (resp && resp.padron) {
                        autocompletarDesdePadron(resp.padron);
                        mostrarMensajePadron(resp.padron);
                    }
                })
                .fail(function () {
                    toast('error', 'No se pudo verificar el documento.');
                })
                .always(function () {
                    verificacionEnCurso = false;
                    $boton.prop('disabled', false);
                });
        });

        // --- Provincia → Localidad (selects linkeados, país fijo Argentina) ---
        // Carga las localidades de una provincia en su select de localidad, con
        // una opción a preseleccionar (para edición/ver). Devuelve la promesa AJAX.
        function cargarLocalidades($localidad, provincia, seleccionar) {
            $localidad.html('<option value="">Seleccionar</option>');
            if (!provincia || !rutas.localidades) {
                return $.Deferred().resolve().promise();
            }
            const buscado = seleccionar ? normalizarTexto(seleccionar) : null;
            return $.getJSON(rutas.localidades, { provincia: provincia })
                .done(function (resp) {
                    (resp.localidades || []).forEach(function (nombre) {
                        const sel = (buscado !== null && normalizarTexto(nombre) === buscado) ? ' selected' : '';
                        $localidad.append('<option value="' + esc(nombre) + '"' + sel + '>' + esc(nombre) + '</option>');
                    });
                });
        }

        // Al cambiar una provincia, recargar su localidad emparejada (por nombre).
        $form.on('change', '.js-provincia', function () {
            const target = $(this).data('localidad-target');
            const $loc = $form.find('.js-localidad[data-provincia="' + target + '"]');
            cargarLocalidades($loc, $(this).val(), null);
        });

        // Precarga los pares provincia/localidad de un cliente (edición/ver).
        function precargarLocalidades(cliente) {
            $form.find('.js-provincia').each(function () {
                const target = $(this).data('localidad-target');
                const $loc = $form.find('.js-localidad[data-provincia="' + target + '"]');
                const provincia = $(this).val();
                if (provincia) {
                    cargarLocalidades($loc, provincia, cliente[target]);
                }
            });
        }

        // Inicializa (una vez) los tooltips de ayuda del modal.
        let tooltipsListos = false;
        function initTooltips() {
            if (tooltipsListos || !(window.bootstrap && window.bootstrap.Tooltip)) {
                return;
            }
            $modal.find('[data-bs-toggle="tooltip"]').each(function () {
                new window.bootstrap.Tooltip(this);
            });
            tooltipsListos = true;
        }

        function abrirModal(titulo) {
            $('#modal-cliente-titulo').text(titulo);
            initTooltips();
            if (modal) {
                modal.show();
            } else {
                $modal.show();
            }
        }

        function cerrarModal() {
            if (modal) {
                modal.hide();
            } else {
                $modal.hide();
            }
        }

        $('#btn-nuevo-cliente').on('click', function () {
            resetForm();
            abrirModal('Nuevo Cliente');
        });

        // Exportar a Excel/CSV respetando la búsqueda actual.
        $('#btn-exportar').on('click', function () {
            const params = new URLSearchParams({
                buscar: (tabla.search() || ''),
            });
            window.location = rutas.export + '?' + params.toString();
            toast('info', 'Generando la exportación...');
        });


        // Cargar un cliente en el formulario (compartido por Ver y Editar).
        function cargarCliente(id, soloLectura, titulo) {
            resetForm();
            $.getJSON(rutas.show + '/' + id)
                .done(function (resp) {
                    const c = resp.cliente;
                    $('#cliente-id').val(c.id);
                    const complejos = ['campos_personalizados', 'contactos'];
                    Object.keys(c).forEach(function (campo) {
                        const $input = $form.find('[name="' + campo + '"]');
                        if ($input.length && complejos.indexOf(campo) === -1) {
                            // Los campos de fecha guardan ISO pero MUESTRAN dd/mm/aaaa: si se les
                            // asigna el ISO crudo, `AppFecha.serializeArray` no lo reconoce como
                            // fecha argentina y lo manda vacío — o sea, abrir y guardar borraría
                            // la fecha. Ver `resources/js/fecha-ar.js`.
                            if ($input.is('[data-fecha-ar]')) {
                                AppFecha.set($input, c[campo]);
                            } else {
                                $input.val(c[campo] === null ? '' : c[campo]);
                            }
                        }
                    });
                    // Campos adicionales propios de este cliente (nombre/tipo/opciones/valor).
                    (c.campos_personalizados || []).forEach(function (campo) {
                        renderCampoAdicional(campo);
                    });
                    // Localidades dependientes de la provincia (precarga por nombre).
                    precargarLocalidades(c);
                    // Personas de contacto.
                    (c.contactos || []).forEach(function (item) {
                        agregarContacto(item);
                    });
                    // Mostrar saldo inicial si tiene monto o fecha cargada.
                    if ((c.saldo_inicial && parseFloat(c.saldo_inicial) !== 0) || c.saldo_inicial_fecha) {
                        $('#saldo-inicial-wrap').removeClass('d-none');
                    }
                    setSoloLectura(soloLectura);
                    abrirModal(titulo);
                })
                .fail(function () {
                    toast('error', 'No se pudo cargar el cliente.');
                });
        }

        // Ver (sólo lectura) / Editar desde el dropdown de acciones.
        // Delegado en document (no en $tabla): dropdown-escape-scroll.js
        // reparenta el .dropdown-menu a <body> al abrirse, así que al
        // momento del click el botón ya no es descendiente de $tabla.
        $(document).on('click', '.js-cliente-ver', function () {
            cargarCliente($(this).data('id'), true, 'Ver Cliente');
        });
        $(document).on('click', '.js-cliente-editar', function () {
            cargarCliente($(this).data('id'), false, 'Editar Cliente');
        });

        // Submit (store/update) por AJAX (US1).
        $form.on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const id = $('#cliente-id').val();
            const esEdicion = !!id;
            const url = esEdicion ? rutas.show + '/' + id : rutas.store;
            const datos = AppFecha.serializeArray($form);
            if (esEdicion) {
                datos.push({ name: '_method', value: 'PATCH' });
            }

            window.AppBtn.loading('#btn-guardar-cliente', true);

            $.ajax({
                url: url,
                method: 'POST',
                data: $.param(datos),
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
                        toast('error', 'No se pudo guardar el cliente.');
                    }
                })
                .always(function () {
                    window.AppBtn.loading('#btn-guardar-cliente', false);
                });
        });

        function mostrarErrores(errors) {
            Object.keys(errors).forEach(function (campo) {
                const nombreCampo = campo.split('.')[0];
                const $input = $form.find('[name="' + campo + '"], [name="' + nombreCampo + '"]');
                $input.addClass('is-invalid');
                $form.find('.invalid-feedback[data-field="' + nombreCampo + '"]').text(errors[campo][0]);
            });
        }

        // --- Estado: inactivar/reactivar (US4) ---
        $(document).on('click', '.js-cliente-estado', function () {
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
                .fail(function () {
                    toast('error', 'No se pudo cambiar el estado.');
                });
        });

        // --- Eliminar (US4) ---
        const $modalEliminar = $('#modal-eliminar-cliente');
        const modalEliminar = window.bootstrap ? new window.bootstrap.Modal($modalEliminar[0]) : null;
        let idAEliminar = null;

        $(document).on('click', '.js-cliente-eliminar', function () {
            idAEliminar = $(this).data('id');
            if (modalEliminar) {
                modalEliminar.show();
            } else {
                $modalEliminar.show();
            }
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
                    const msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || 'No se pudo eliminar el cliente.';
                    toast('error', msg);
                })
                .always(function () {
                    if (modalEliminar) {
                        modalEliminar.hide();
                    } else {
                        $modalEliminar.hide();
                    }
                    idAEliminar = null;
                });
        });

        // --- Personas de contacto (dinámico, alineado a Contagram) ---
        let contactoIdx = 0;
        function agregarContacto(item) {
            item = item || {};
            const idx = contactoIdx++;
            const checked = item.enviar_mails ? 'checked' : '';
            const html =
                '<div class="js-contacto border-top pt-3 mb-2">' +
                '  <div class="mb-2 d-flex align-items-end gap-2">' +
                '    <div class="flex-grow-1"><label class="form-label">Nombre <span class="text-danger">*</span></label>' +
                '      <input type="text" class="form-control" name="contactos[' + idx + '][nombre]" value="' + esc(item.nombre) + '"></div>' +
                '    <button type="button" class="btn btn-outline-danger js-quitar-contacto" title="Quitar"><i class="fas fa-trash-alt"></i></button>' +
                '  </div>' +
                '  <div class="mb-2"><label class="form-label">Apellido</label>' +
                '    <input type="text" class="form-control" name="contactos[' + idx + '][apellido]" value="' + esc(item.apellido) + '"></div>' +
                '  <div class="row g-2 mb-2">' +
                '    <div class="col-6"><label class="form-label">Teléfono</label>' +
                '      <input type="text" class="form-control" name="contactos[' + idx + '][telefono]" value="' + esc(item.telefono) + '"></div>' +
                '    <div class="col-6"><label class="form-label">Cel.</label>' +
                '      <input type="text" class="form-control" name="contactos[' + idx + '][telefono_celular]" value="' + esc(item.telefono_celular) + '"></div>' +
                '  </div>' +
                '  <div class="mb-2"><label class="form-label">Email</label>' +
                '    <input type="email" class="form-control" name="contactos[' + idx + '][email]" value="' + esc(item.email) + '"></div>' +
                '  <div class="form-check">' +
                '    <input class="form-check-input" type="checkbox" value="1" name="contactos[' + idx + '][enviar_mails]" id="contacto-mails-' + idx + '" ' + checked + '>' +
                '    <label class="form-check-label" for="contacto-mails-' + idx + '">Enviar también mails a esta dirección</label>' +
                '  </div>' +
                '</div>';
            $('#contactos-container').append(html);
        }

        $('#btn-agregar-contacto').on('click', function () {
            agregarContacto({});
        });

        $('#contactos-container').on('click', '.js-quitar-contacto', function () {
            $(this).closest('.js-contacto').remove();
        });

        // --- Toggle Saldo Inicial ---
        $('#btn-toggle-saldo').on('click', function () {
            const $wrap = $('#saldo-inicial-wrap').toggleClass('d-none');
            // Al abrirlo sin fecha cargada, prefijar hoy (fecha de apertura de la cta cte).
            if (!$wrap.hasClass('d-none')) {
                const $fecha = $wrap.find('[name="saldo_inicial_fecha"]');
                if (!AppFecha.get($fecha)) {
                    AppFecha.set($fecha, AppFecha.hoy());
                }
            }
        });

        // ===================== Campos personalizados =====================
        // "Crear nuevo campo": modal con definición tipada (Texto/Numérico/Fecha/Opciones).
        const $modalCampo = $('#modal-nuevo-campo');
        const modalCampo = window.bootstrap ? new window.bootstrap.Modal($modalCampo[0]) : null;
        let opcionesNuevoCampo = [];

        function resetModalCampo() {
            $('#nuevo-campo-nombre').val('').removeClass('is-invalid');
            $('#nuevo-campo-tipo').val('texto');
            $('#nuevo-campo-opcion-input').val('');
            $('#nuevo-campo-error-nombre').text('');
            $('#nuevo-campo-error-opciones').text('');
            opcionesNuevoCampo = [];
            renderOpcionesNuevoCampo();
            $('#nuevo-campo-opciones-wrap').addClass('d-none');
        }

        function renderOpcionesNuevoCampo() {
            const $lista = $('#nuevo-campo-opciones-lista').empty();
            opcionesNuevoCampo.forEach(function (op, i) {
                $lista.append(
                    '<li class="d-flex align-items-center mb-1">' +
                    '  <span class="me-2">-</span><span class="flex-grow-1">' + esc(op) + '</span>' +
                    '  <button type="button" class="btn btn-sm btn-link text-danger js-quitar-opcion" data-i="' + i + '">x</button>' +
                    '</li>'
                );
            });
        }

        $('#btn-agregar-campo').on('click', function () {
            resetModalCampo();
            if (modalCampo) {
                modalCampo.show();
            } else {
                $modalCampo.show();
            }
        });

        $('#nuevo-campo-tipo').on('change', function () {
            $('#nuevo-campo-opciones-wrap').toggleClass('d-none', $(this).val() !== 'opciones');
        });

        $('#btn-agregar-opcion').on('click', function () {
            const val = ($('#nuevo-campo-opcion-input').val() || '').trim();
            if (!val) {
                return;
            }
            opcionesNuevoCampo.push(val);
            $('#nuevo-campo-opcion-input').val('');
            renderOpcionesNuevoCampo();
        });

        $('#nuevo-campo-opcion-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#btn-agregar-opcion').click();
            }
        });

        $('#nuevo-campo-opciones-lista').on('click', '.js-quitar-opcion', function () {
            opcionesNuevoCampo.splice($(this).data('i'), 1);
            renderOpcionesNuevoCampo();
        });

        // Render de un campo adicional del cliente. La definición (nombre/tipo/
        // opciones) viaja en inputs hidden junto al valor; TODO se guarda dentro
        // del cliente (no hay catálogo global). En alta se crea en el front y se
        // persiste al guardar; en edición se reconstruye desde el JSON del cliente.
        let campoAdicionalIdx = 0;
        function renderCampoAdicional(def) {
            def = def || {};
            const idx = campoAdicionalIdx++;
            const base = 'campos_personalizados[' + idx + ']';
            const tipo = def.tipo || 'texto';
            const opciones = def.opciones || [];
            const valor = (def.valor === null || def.valor === undefined) ? '' : def.valor;
            let input;
            if (tipo === 'opciones') {
                input = '<select class="form-select" name="' + base + '[valor]"><option value="">Seleccionar</option>';
                opciones.forEach(function (op) {
                    const sel = (op === valor) ? ' selected' : '';
                    input += '<option value="' + esc(op) + '"' + sel + '>' + esc(op) + '</option>';
                });
                input += '</select>';
            } else if (tipo === 'numerico') {
                input = '<input type="number" step="any" class="form-control" name="' + base + '[valor]" value="' + esc(valor) + '">';
            } else if (tipo === 'fecha') {
                input = '<input type="date" class="form-control" name="' + base + '[valor]" value="' + esc(valor) + '">';
            } else {
                input = '<input type="text" class="form-control" name="' + base + '[valor]" value="' + esc(valor) + '">';
            }

            // Definición del campo (se persiste asociada a ESTE cliente al guardar).
            let hidden =
                '<input type="hidden" name="' + base + '[nombre]" value="' + esc(def.nombre) + '">' +
                '<input type="hidden" name="' + base + '[tipo]" value="' + esc(tipo) + '">';
            opciones.forEach(function (op) {
                hidden += '<input type="hidden" name="' + base + '[opciones][]" value="' + esc(op) + '">';
            });

            $('#campos-personalizados-container').append(
                '<div class="mb-3 js-campo-personalizado">' +
                '  <label class="form-label d-flex justify-content-between align-items-center">' +
                '    <span>' + esc(def.nombre) + '</span>' +
                '    <button type="button" class="btn btn-sm btn-link text-danger p-0 js-quitar-campo" title="Quitar">' +
                '      <i class="fas fa-times"></i></button>' +
                '  </label>' +
                input + hidden +
                '</div>'
            );
        }

        // Quitar un campo adicional del cliente.
        $('#campos-personalizados-container').on('click', '.js-quitar-campo', function () {
            $(this).closest('.js-campo-personalizado').remove();
        });

        // "Guardar" del sub-modal: NO pega al servidor, sólo arma el campo en el
        // front. La creación/asociación real ocurre al guardar el cliente.
        $('#btn-guardar-campo').on('click', function () {
            const nombre = ($('#nuevo-campo-nombre').val() || '').trim();
            const tipo = $('#nuevo-campo-tipo').val();
            $('#nuevo-campo-nombre').removeClass('is-invalid');
            $('#nuevo-campo-error-nombre').text('');
            $('#nuevo-campo-error-opciones').text('');

            if (!nombre) {
                $('#nuevo-campo-nombre').addClass('is-invalid');
                $('#nuevo-campo-error-nombre').text('Ingresá un nombre.');
                return;
            }

            const def = { nombre: nombre, tipo: tipo, opciones: [] };
            if (tipo === 'opciones') {
                if (!opcionesNuevoCampo.length) {
                    $('#nuevo-campo-error-opciones').text('Agregá al menos una opción.');
                    return;
                }
                def.opciones = opcionesNuevoCampo.slice();
            }

            renderCampoAdicional(def);
            if (modalCampo) {
                modalCampo.hide();
            } else {
                $modalCampo.hide();
            }
        });
    });
})();
