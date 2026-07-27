/**
 * Configuración → Roles y Permisos (spec 013, US2).
 * DataTable server-side + modal con matriz de permisos por módulo, todo por AJAX.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[configuracion-roles] jQuery no está disponible.');
        return;
    }

    const cfg = window.RolesConfig || {};
    const rutas = cfg.rutas || {};
    let catalogoModulos = [];

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
            console.log('[configuracion-roles][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function limpiarErrores($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.invalid-feedback').text('');
    }

    function mostrarErrores($form, errors) {
        limpiarErrores($form);
        $.each(errors || {}, function (campo, mensajes) {
            const $input = $form.find('[name="' + campo + '"]');
            $input.addClass('is-invalid');
            $form.find('[data-field="' + campo + '"]').text(mensajes[0]);
        });
    }

    function pintarMatriz(codigosSeleccionados) {
        const seleccionados = codigosSeleccionados || [];
        const html = catalogoModulos.map(function (grupo) {
            const items = grupo.permisos.map(function (p) {
                const checked = seleccionados.indexOf(p.codigo) !== -1 ? 'checked' : '';
                const idSeguro = p.codigo.replace(/\./g, '-');
                return '<div class="form-check">'
                    + '<input class="form-check-input" type="checkbox" name="permisos[]" value="' + p.codigo + '" id="permiso-' + idSeguro + '" ' + checked + '>'
                    + '<label class="form-check-label" for="permiso-' + idSeguro + '">' + p.descripcion + '</label>'
                    + '</div>';
            }).join('');
            return '<div class="mb-2"><strong class="text-capitalize">' + grupo.modulo + '</strong>' + items + '</div>';
        }).join('');
        $('#permisos-matriz').html(html || '<p class="text-muted mb-0">Sin permisos en el catálogo.</p>');
    }

    function resetForm() {
        const $form = $('#form-rol');
        $form[0].reset();
        $('#rol-id').val('');
        limpiarErrores($form);
        pintarMatriz([]);
        $('#modal-rol-titulo').text('Nuevo Rol');
    }

    $(function () {
        const $tabla = $('#tabla-roles');
        if (!$tabla.length) {
            return;
        }

        $.getJSON(rutas.permisos).done(function (resp) {
            catalogoModulos = resp.modulos || [];
        });

        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            responsive: true,
            language: {
                search: 'Buscar:',
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ roles',
                infoEmpty: 'Sin roles',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron roles',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data },
            columns: [
                { data: 'nombre', name: 'nombre' },
                { data: 'descripcion', name: 'descripcion', defaultContent: '' },
                { data: 'permisos_count', name: 'permisos_count', orderable: false },
                { data: 'usuarios_count', name: 'usuarios_count', orderable: false },
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
            ],
        });

        const $modal = $('#modal-rol');
        const modalRol = new window.bootstrap.Modal($modal[0]);

        $('#btn-nuevo-rol').on('click', function () {
            resetForm();
            modalRol.show();
        });

        $tabla.on('click', '.js-rol-editar', function () {
            const id = $(this).data('id');
            resetForm();

            $.getJSON(rutas.show + '/' + id).done(function (resp) {
                const r = resp.rol;
                $('#rol-id').val(r.id);
                $('#form-rol [name="nombre"]').val(r.nombre);
                $('#form-rol [name="descripcion"]').val(r.descripcion);
                pintarMatriz((r.permisos || []).map(function (p) { return p.codigo; }));
                $('#modal-rol-titulo').text('Editar Rol');
                modalRol.show();
            }).fail(function () {
                toast('error', 'No se pudo cargar el rol.');
            });
        });

        $tabla.on('click', '.js-rol-eliminar', function () {
            const id = $(this).data('id');

            if (!confirm('¿Eliminar este rol?')) {
                return;
            }

            $.ajax({
                url: rutas.show + '/' + id,
                method: 'DELETE',
                dataType: 'json',
            }).done(function (resp) {
                toast('success', resp.mensaje);
                tabla.ajax.reload(null, false);
            }).fail(function (jqXHR) {
                const resp = jqXHR.responseJSON || {};
                toast('error', resp.mensaje || 'No se pudo eliminar el rol.');
            });
        });

        $('#form-rol').on('submit', function (e) {
            e.preventDefault();
            const $form = $(this);
            limpiarErrores($form);

            const id = $('#rol-id').val();
            const datos = {
                nombre: $form.find('[name="nombre"]').val(),
                descripcion: $form.find('[name="descripcion"]').val(),
                permisos: $form.find('input[name="permisos[]"]:checked').map(function () {
                    return $(this).val();
                }).get(),
            };

            const url = id ? rutas.show + '/' + id : rutas.store;
            const method = id ? 'PUT' : 'POST';

            $.ajax({ url, method, dataType: 'json', data: datos }).done(function (resp) {
                toast('success', resp.mensaje);
                modalRol.hide();
                tabla.ajax.reload(null, false);
            }).fail(function (jqXHR) {
                if (jqXHR.status === 422) {
                    const resp = jqXHR.responseJSON || {};
                    mostrarErrores($form, resp.errors);
                    toast('error', 'Revisá los datos ingresados.');
                } else {
                    toast('error', 'No se pudo guardar el rol.');
                }
            });
        });
    });
})();
