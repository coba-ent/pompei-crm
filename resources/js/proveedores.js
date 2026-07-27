/**
 * Módulo Proveedores — DataTable server-side + modales AJAX + toasts.
 *
 * Espejo de clientes.js (mismo patrón: jQuery, Toastr, DataTables cargados
 * globalmente por el template NexaDash vía config/dz.php pagelevel 'proveedores').
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[proveedores] jQuery no está disponible.');
        return;
    }

    const cfg = window.ProveedoresConfig || {};
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
            console.log('[proveedores][' + tipo + ']', mensaje);
        }
    }

    function esc(v) {
        return (v || v === 0) ? $('<div>').text(v).html() : '';
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    $(function () {
        const $tabla = $('#tabla-proveedores');
        if (!$tabla.length) {
            return;
        }

        // --- DataTable server-side + ColVis con persistencia en navegador ---
        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            responsive: true,
            stateSave: true,
            stateDuration: 0, // 0 = indefinido (localStorage), persiste entre sesiones.
            dom:
                "<'row mb-2 align-items-center'<'col-sm-6'l>>" +
                "<'row'<'col-sm-12'tr>>" +
                "<'row mt-2 align-items-center'<'col-sm-5'i><'col-sm-7'p>>",
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ proveedores',
                infoEmpty: 'Sin proveedores',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron proveedores',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
                buttons: { colvis: 'Columnas' },
            },
            ajax: {
                url: rutas.data,
            },
            columns: [
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
                { data: 'nota', name: 'nota', defaultContent: '' },
                { data: 'pagina_web', name: 'pagina_web', defaultContent: '' },
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'text-end no-colvis' },
            ],
            order: [[1, 'asc']],
        });

        // Botón ColVis ("Columnas") creado DESPUÉS de la tabla y anexado al toolbar.
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
            tabla.buttons().container().appendTo('#proveedores-colvis');
        }

        // --- Buscador estilo Contagram (busca por cualquier dato, server-side) ---
        $('#buscador-proveedores').val(tabla.search() || '');

        function ejecutarBusqueda() {
            tabla.search($('#buscador-proveedores').val() || '').draw();
        }
        $('#btn-buscar-proveedores').on('click', ejecutarBusqueda);
        $('#buscador-proveedores').on('keydown', function (e) {
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
                $('#stat-nuevos').text(fmt(s.nuevos_mes));
            });
        }

        // --- Modal de alta/edición ---
        const $modal = $('#modal-proveedor');
        const modal = window.bootstrap ? new window.bootstrap.Modal($modal[0]) : null;
        const $form = $('#form-proveedor');

        function limpiarErrores() {
            $form.find('.is-invalid').removeClass('is-invalid');
            $form.find('.invalid-feedback').text('');
        }

        // Modo sólo lectura (acción "Ver").
        function setSoloLectura(activo) {
            $form.find('input, select, textarea').prop('disabled', activo);
            $form.find('button').not('[data-bs-dismiss="modal"]').prop('disabled', activo);
            $('#btn-guardar-proveedor').toggleClass('d-none', activo);
        }

        function resetForm() {
            $form[0].reset();
            $('#proveedor-id').val('');
            limpiarErrores();
            setSoloLectura(false);
            $('#contactos-container').empty();
            $('#campos-personalizados-container').empty();
            // Vaciar los selects de localidad (dependen de la provincia).
            $form.find('.js-localidad').html('<option value="">Seleccionar</option>');
            $('#saldo-inicial-wrap').addClass('d-none');
        }

        // --- Provincia → Localidad (selects linkeados, país fijo Argentina) ---
        function cargarLocalidades($localidad, provincia, seleccionar) {
            $localidad.html('<option value="">Seleccionar</option>');
            if (!provincia || !rutas.localidades) {
                return $.Deferred().resolve().promise();
            }
            return $.getJSON(rutas.localidades, { provincia: provincia })
                .done(function (resp) {
                    (resp.localidades || []).forEach(function (nombre) {
                        const sel = (nombre === seleccionar) ? ' selected' : '';
                        $localidad.append('<option value="' + esc(nombre) + '"' + sel + '>' + esc(nombre) + '</option>');
                    });
                });
        }

        $form.on('change', '.js-provincia', function () {
            const target = $(this).data('localidad-target');
            const $loc = $form.find('.js-localidad[data-provincia="' + target + '"]');
            cargarLocalidades($loc, $(this).val(), null);
        });

        // Precarga los pares provincia/localidad de un proveedor (edición/ver).
        function precargarLocalidades(proveedor) {
            $form.find('.js-provincia').each(function () {
                const target = $(this).data('localidad-target');
                const $loc = $form.find('.js-localidad[data-provincia="' + target + '"]');
                const provincia = $(this).val();
                if (provincia) {
                    cargarLocalidades($loc, provincia, proveedor[target]);
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
            $('#modal-proveedor-titulo').text(titulo);
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

        $('#btn-nuevo-proveedor').on('click', function () {
            resetForm();
            abrirModal('Nuevo Proveedor');
        });

        // Exportar a Excel/CSV respetando la búsqueda actual.
        $('#btn-exportar').on('click', function () {
            const params = new URLSearchParams({
                buscar: (tabla.search() || ''),
            });
            window.location = rutas.export + '?' + params.toString();
            toast('info', 'Generando la exportación...');
        });

        // Cargar un proveedor en el formulario (compartido por Ver y Editar).
        function cargarProveedor(id, soloLectura, titulo) {
            resetForm();
            $.getJSON(rutas.show + '/' + id)
                .done(function (resp) {
                    const p = resp.proveedor;
                    $('#proveedor-id').val(p.id);
                    const complejos = ['campos_personalizados', 'contactos'];
                    Object.keys(p).forEach(function (campo) {
                        const $input = $form.find('[name="' + campo + '"]');
                        if ($input.length && complejos.indexOf(campo) === -1) {
                            $input.val(p[campo] === null ? '' : p[campo]);
                        }
                    });
                    // Campos adicionales propios de este proveedor.
                    (p.campos_personalizados || []).forEach(function (campo) {
                        renderCampoAdicional(campo);
                    });
                    // Localidades dependientes de la provincia (precarga por nombre).
                    precargarLocalidades(p);
                    // Personas de contacto.
                    (p.contactos || []).forEach(function (item) {
                        agregarContacto(item);
                    });
                    // Mostrar saldo inicial si tiene monto o fecha cargada.
                    if ((p.saldo_inicial && parseFloat(p.saldo_inicial) !== 0) || p.saldo_inicial_fecha) {
                        $('#saldo-inicial-wrap').removeClass('d-none');
                    }
                    setSoloLectura(soloLectura);
                    abrirModal(titulo);
                })
                .fail(function () {
                    toast('error', 'No se pudo cargar el proveedor.');
                });
        }

        // Ver (sólo lectura) / Editar desde el dropdown de acciones.
        $tabla.on('click', '.js-proveedor-ver', function () {
            cargarProveedor($(this).data('id'), true, 'Ver Proveedor');
        });
        $tabla.on('click', '.js-proveedor-editar', function () {
            cargarProveedor($(this).data('id'), false, 'Editar Proveedor');
        });

        // Submit (store/update) por AJAX.
        $form.on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const id = $('#proveedor-id').val();
            const esEdicion = !!id;
            const url = esEdicion ? rutas.show + '/' + id : rutas.store;
            const datos = $form.serializeArray();
            if (esEdicion) {
                datos.push({ name: '_method', value: 'PATCH' });
            }

            $('#btn-guardar-proveedor').prop('disabled', true);

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
                        toast('error', 'No se pudo guardar el proveedor.');
                    }
                })
                .always(function () {
                    $('#btn-guardar-proveedor').prop('disabled', false);
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

        // --- Estado: inactivar/reactivar ---
        $tabla.on('click', '.js-proveedor-estado', function () {
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

        // --- Eliminar ---
        const $modalEliminar = $('#modal-eliminar-proveedor');
        const modalEliminar = window.bootstrap ? new window.bootstrap.Modal($modalEliminar[0]) : null;
        let idAEliminar = null;

        $tabla.on('click', '.js-proveedor-eliminar', function () {
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
                    const msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || 'No se pudo eliminar el proveedor.';
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
            if (!$wrap.hasClass('d-none')) {
                const $fecha = $wrap.find('[name="saldo_inicial_fecha"]');
                if (!$fecha.val()) {
                    $fecha.val(new Date().toISOString().slice(0, 10));
                }
            }
        });

        // ===================== Campos personalizados =====================
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

        $('#campos-personalizados-container').on('click', '.js-quitar-campo', function () {
            $(this).closest('.js-campo-personalizado').remove();
        });

        // "Guardar" del sub-modal: NO pega al servidor, sólo arma el campo en el
        // front. La creación/asociación real ocurre al guardar el proveedor.
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
