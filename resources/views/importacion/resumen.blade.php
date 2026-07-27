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

    </div>
</div>
@endsection
