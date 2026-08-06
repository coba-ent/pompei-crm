/**
 * Configuración → Usuarios y Permisos (spec 013, US2).
 * DataTable server-side + modal alta/edición/baja lógica AJAX + toasts.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[configuracion-usuarios] jQuery no está disponible.');
        return;
    }

    const cfg = window.UsuariosConfig || {};
    const rutas = cfg.rutas || {};
    const roles = cfg.roles || [];

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
            console.log('[configuracion-usuarios][' + tipo + ']', mensaje);
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

    function pintarChecklistRoles(rolesSeleccionados) {
        const seleccionados = rolesSeleccionados || [];
        const html = roles.map(function (rol) {
            const checked = seleccionados.indexOf(rol.id) !== -1 ? 'checked' : '';
            return '<div class="form-check">'
                + '<input class="form-check-input" type="checkbox" name="roles[]" value="' + rol.id + '" id="rol-' + rol.id + '" ' + checked + '>'
                + '<label class="form-check-label" for="rol-' + rol.id + '">' + rol.nombre + '</label>'
                + '</div>';
        }).join('');
        $('#roles-checklist').html(html);
    }

    function resetForm() {
        const $form = $('#form-usuario');
        $form[0].reset();
        $('#usuario-id').val('');
        limpiarErrores($form);
        pintarChecklistRoles([]);
        $('#modal-usuario-titulo').text('Nuevo Usuario');
        $('input[name="password"]').prop('required', true);
        $('#password-required').show();
        $('#password-help').hide();
    }

    $(function () {
        const $tabla = $('#tabla-usuarios');
        if (!$tabla.length) {
            return;
        }

        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: false,
            paging: false,
            language: {
                search: 'Buscar:',
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ usuarios',
                infoEmpty: 'Sin usuarios',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron usuarios',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'dt-acciones-caret no-colvis' },
                { data: 'name', name: 'name' },
                { data: 'email', name: 'email' },
                { data: 'roles', name: 'roles', orderable: false },
                {
                    data: 'activo', name: 'activo', orderable: false,
                    render: function (activo) {
                        return activo
                            ? '<span class="badge bg-success">Activo</span>'
                            : '<span class="badge bg-secondary">Inactivo</span>';
                    },
                },
            ],
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

        $tabla.one('init.dt', function () {
            tabla.buttons().container().appendTo('#dt-buttons-usuarios');
        });

        const $modal = $('#modal-usuario');
        const modalUsuario = new window.bootstrap.Modal($modal[0]);

        $('#btn-nuevo-usuario').on('click', function () {
            resetForm();
            modalUsuario.show();
        });

        // Delegado en document (no en $tabla): el fix global de dropdowns
        // (dropdown-escape-scroll.js) reparenta el .dropdown-menu a <body>
        // al abrirse para evitar que quede recortado por el scroll de la
        // tabla, así que al momento del click el botón ya no es descendiente
        // de $tabla y un delegado ahí nunca se dispara.
        $(document).on('click', '.js-usuario-editar', function () {
            const id = $(this).data('id');
            resetForm();

            $.getJSON(rutas.show + '/' + id).done(function (resp) {
                const u = resp.usuario;
                $('#usuario-id').val(u.id);
                $('#form-usuario [name="name"]').val(u.name);
                $('#form-usuario [name="email"]').val(u.email);
                pintarChecklistRoles((u.roles || []).map(function (r) { return r.id; }));
                $('#modal-usuario-titulo').text('Editar Usuario');
                $('input[name="password"]').prop('required', false);
                $('#password-required').hide();
                $('#password-help').show();
                modalUsuario.show();
            }).fail(function () {
                toast('error', 'No se pudo cargar el usuario.');
            });
        });

        $(document).on('click', '.js-usuario-estado', function () {
            const id = $(this).data('id');
            const activo = $(this).data('activo') === 1;

            if (!confirm(activo ? '¿Inactivar este usuario?' : '¿Reactivar este usuario?')) {
                return;
            }

            $.ajax({
                url: rutas.show + '/' + id + '/estado',
                method: 'PATCH',
                dataType: 'json',
            }).done(function (resp) {
                toast('success', resp.mensaje);
                tabla.ajax.reload(null, false);
            }).fail(function (jqXHR) {
                const resp = jqXHR.responseJSON || {};
                toast('error', resp.mensaje || 'No se pudo cambiar el estado.');
            });
        });

        $('#form-usuario').on('submit', function (e) {
            e.preventDefault();
            const $form = $(this);
            limpiarErrores($form);

            const id = $('#usuario-id').val();
            const datos = {
                name: $form.find('[name="name"]').val(),
                email: $form.find('[name="email"]').val(),
                password: $form.find('[name="password"]').val(),
                roles: $form.find('input[name="roles[]"]:checked').map(function () {
                    return $(this).val();
                }).get(),
            };

            const url = id ? rutas.show + '/' + id : rutas.store;
            const method = id ? 'PUT' : 'POST';

            const $btn = window.AppBtn.loading($form.find('button[type="submit"]'), true);
            $.ajax({ url, method, dataType: 'json', data: datos }).done(function (resp) {
                toast('success', resp.mensaje);
                modalUsuario.hide();
                tabla.ajax.reload(null, false);
            }).fail(function (jqXHR) {
                if (jqXHR.status === 422) {
                    const resp = jqXHR.responseJSON || {};
                    mostrarErrores($form, resp.errors);
                    toast('error', 'Revisá los datos ingresados.');
                } else {
                    toast('error', 'No se pudo guardar el usuario.');
                }
            }).always(function () {
                window.AppBtn.loading($btn, false);
            });
        });
    });
})();
