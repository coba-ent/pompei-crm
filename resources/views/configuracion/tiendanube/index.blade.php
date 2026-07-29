@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Tiendanube</h4>
                <p class="text-muted mb-0">
                    Conectá tu tienda de Tiendanube (Aplicación personalizada) para operar catálogo y ventas
                    desde el CRM.
                </p>
            </div>
        </div>

        @include('configuracion.tiendanube._panel_estado')

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Credenciales de la Aplicación personalizada</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-credenciales-tn">
                    <i class="fas fa-pencil-alt me-1"></i> Editar
                </button>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label text-muted mb-1">Identificador de tienda</label>
                        <div class="fw-bold" id="tn-info-store-id">—</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted mb-1">Token de acceso</label>
                        <div class="fw-bold" id="tn-info-token">—</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Modo sólo lectura</h6>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="tn-modo-solo-lectura">
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">
                    Bloquea toda escritura hacia Tiendanube, registrándola en el historial en vez de
                    ejecutarla. Permite apuntar a la tienda real del negocio sin riesgo mientras se sigue
                    desarrollando el resto del módulo.
                </p>
                <div class="alert alert-warning mt-3 mb-0 d-none" id="tn-aviso-solo-lectura">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Modo sólo lectura activo: las escrituras hacia Tiendanube están bloqueadas.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">Historial de operaciones</h6>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Desde</label>
                        <input type="date" class="form-control form-control-sm" id="tn-historial-desde">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Hasta</label>
                        <input type="date" class="form-control form-control-sm" id="tn-historial-hasta">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Resultado</label>
                        <select class="form-select form-select-sm" id="tn-historial-resultado">
                            <option value="">Todos</option>
                            <option value="exito">Éxito</option>
                            <option value="error">Error</option>
                            <option value="bloqueada">Bloqueada</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btn-filtrar-historial-tn">Filtrar</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped w-100" id="tabla-tn-operaciones">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Operación</th>
                                <th>Sentido</th>
                                <th>Resultado</th>
                                <th>Código</th>
                                <th>Duración</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

@include('configuracion.tiendanube._modal_credenciales')
@include('configuracion.tiendanube._modal_desconectar')
@endsection

@section('local-js')
<script>
    window.TiendanubeConfig = {
        rutas: {
            estado: @json(route('configuracion.tiendanube.estado')),
            credenciales: @json(route('configuracion.tiendanube.credenciales')),
            probar: @json(route('configuracion.tiendanube.probar')),
            desconectar: @json(route('configuracion.tiendanube.desconectar')),
            modoSoloLectura: @json(route('configuracion.tiendanube.modoSoloLectura')),
            historial: @json(route('configuracion.tiendanube.historial')),
        },
    };
</script>
@vite(['resources/js/tiendanube.js'])
@endsection
