@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-12">
                <h4 class="mb-0 text-primary fw-bold">Historial de Importaciones — Productos & Servicios</h4>
                <p class="text-muted mb-0">Corridas confirmadas del asistente de Importar Datos. Podés deshacer una corrida dentro de las 48 horas de haberla confirmado.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tabla-historial-importacion" class="table table-striped w-100">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Archivo</th>
                                <th>Creadas</th>
                                <th>Actualizadas</th>
                                <th>Fallidas</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modal-confirmar-deshacer-historial" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Deshacer import</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Confirmás que querés deshacer esta importación? Los productos creados quedarán inactivos y los actualizados volverán a sus valores anteriores. Esta acción no se puede repetir.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-deshacer-historial">Sí, deshacer</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
window.jQuery(function ($) {
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var corridaSeleccionada = null;

    var tabla = $('#tabla-historial-importacion').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('importacion.historial.datos', 'productos') }}',
        order: [],
        columns: [
            { data: 'confirmado_en' },
            { data: 'usuario' },
            { data: 'archivo_original' },
            { data: 'filas_creadas' },
            { data: 'filas_actualizadas' },
            { data: 'filas_fallidas' },
            {
                data: 'estado',
                render: function (estado) {
                    var badges = { vigente: 'success', deshecho: 'secondary', vencido: 'warning' };
                    var etiquetas = { vigente: 'Vigente', deshecho: 'Deshecho', vencido: 'Vencido' };
                    return '<span class="badge bg-' + (badges[estado] || 'secondary') + '">' + (etiquetas[estado] || estado) + '</span>';
                },
            },
            {
                data: null,
                orderable: false,
                render: function (row) {
                    if (!row.puede_deshacer) { return '—'; }
                    return '<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer-fila" data-corrida="' + row.id + '"><i class="fas fa-undo"></i> Deshacer</button>';
                },
            },
        ],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-AR.json' },
    });

    $('#tabla-historial-importacion tbody').on('click', '.btn-deshacer-fila', function () {
        corridaSeleccionada = $(this).data('corrida');
        var modal = new bootstrap.Modal(document.getElementById('modal-confirmar-deshacer-historial'));
        modal.show();
    });

    $('#btn-confirmar-deshacer-historial').on('click', function () {
        if (!corridaSeleccionada) { return; }
        var $btn = $(this).prop('disabled', true);

        $.post('{{ url('importar-datos/productos/historial') }}/' + corridaSeleccionada + '/deshacer')
            .done(function (resp) {
                if (resp.no_revertidas && resp.no_revertidas.length) {
                    toastr.warning(resp.mensaje);
                } else {
                    toastr.success(resp.mensaje);
                }
                tabla.ajax.reload(null, false);
            })
            .fail(function (xhr) {
                toastr.error(xhr.responseJSON?.error || 'No se pudo deshacer la importación.');
            })
            .always(function () {
                $btn.prop('disabled', false);
                bootstrap.Modal.getInstance(document.getElementById('modal-confirmar-deshacer-historial')).hide();
            });
    });
});
</script>
@endsection
