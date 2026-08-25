@extends('layouts.default')

@php
    $titulos = ['clientes' => 'Clientes', 'proveedores' => 'Proveedores', 'productos' => 'Productos & Servicios'];
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-12">
                <h4 class="mb-0 text-primary fw-bold">Importar Datos — {{ $titulos[$entidad] }}</h4>
                <p class="text-muted mb-0">Resultado de la importación.</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="text-success">
                    <i class="fas fa-check-circle me-1"></i>
                    {{ $resultado['importados'] }} {{ Str::plural('registro', $resultado['importados']) }} importado{{ $resultado['importados'] === 1 ? '' : 's' }} correctamente.
                </h5>

                @if (count($resultado['fallidos']))
                    <hr>
                    <h6 class="text-danger">{{ count($resultado['fallidos']) }} fila{{ count($resultado['fallidos']) === 1 ? '' : 's' }} no importada{{ count($resultado['fallidos']) === 1 ? '' : 's' }}</h6>
                    <ul class="mb-0">
                        @foreach ($resultado['fallidos'] as $fallo)
                            <li>Fila {{ $fallo['fila'] }}: {{ $fallo['motivo'] }}</li>
                        @endforeach
                    </ul>
                @endif

                @if (count($resultado['advertencias']))
                    <hr>
                    <h6 class="text-warning">Advertencias</h6>
                    <ul class="mb-0">
                        @foreach ($resultado['advertencias'] as $advertencia)
                            <li>Fila {{ $advertencia['fila'] }}: {{ $advertencia['motivo'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <a href="{{ route($entidad.'.index') }}" class="btn btn-primary">
            <i class="fas fa-arrow-left me-1"></i> Ver mis {{ $titulos[$entidad] }}
        </a>

        @if ($entidad === 'productos' && $corrida && $corrida->puedeDeshacer())
            <button type="button" class="btn btn-outline-danger" id="btn-deshacer-import" data-corrida="{{ $corrida->id }}">
                <i class="fas fa-undo me-1"></i> Deshacer este import
            </button>
            <a href="{{ route('importacion.historial', 'productos') }}" class="btn btn-link">Ver historial de importaciones</a>
        @endif

    </div>
</div>

@if ($entidad === 'productos' && $corrida && $corrida->puedeDeshacer())
<div class="modal fade" id="modal-confirmar-deshacer" tabindex="-1">
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
                <button type="button" class="btn btn-danger" id="btn-confirmar-deshacer">Sí, deshacer</button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@if ($entidad === 'productos' && $corrida && $corrida->puedeDeshacer())
@section('local-js')
<script>
window.jQuery(function ($) {
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var corridaId = $('#btn-deshacer-import').data('corrida');

    $('#btn-deshacer-import').on('click', function () {
        var modal = new bootstrap.Modal(document.getElementById('modal-confirmar-deshacer'));
        modal.show();
    });

    $('#btn-confirmar-deshacer').on('click', function () {
        var $btn = $(this).prop('disabled', true);

        $.post('{{ url('importar-datos/productos/historial') }}/' + corridaId + '/deshacer')
            .done(function (resp) {
                if (resp.no_revertidas && resp.no_revertidas.length) {
                    toastr.warning(resp.mensaje);
                } else {
                    toastr.success(resp.mensaje);
                }
                $('#btn-deshacer-import').prop('disabled', true).text('Import deshecho');
            })
            .fail(function (xhr) {
                toastr.error(xhr.responseJSON?.error || 'No se pudo deshacer la importación.');
            })
            .always(function () {
                $btn.prop('disabled', false);
                bootstrap.Modal.getInstance(document.getElementById('modal-confirmar-deshacer')).hide();
            });
    });
});
</script>
@endsection
@endif
