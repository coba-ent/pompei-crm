@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-3">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Tesorería</h4>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('tesoreria.saldos') }}">Saldos</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('tesoreria.movimientos') }}">Movimientos</a>
            </li>
        </ul>

        <div class="alert alert-info alert-dismissible fade show" id="banner-movimientos">
            <strong>¿Qué contempla este informe?</strong>
            <ul class="mb-0 mt-1">
                <li><strong>Cobros</strong>: todos los cobros realizados sobre Ventas + todos los ingresos registrados en Otros Ingresos.</li>
                <li><strong>Pagos</strong>: todos los pagos realizados sobre Compras + todos los pagos realizados al registrar Gastos (los Gastos en estado "Pendiente" quedan excluidos).</li>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>

        <div class="row align-items-end mb-3 g-2">
            <div class="col-md-3">
                <label class="form-label mb-1">Desde</label>
                <input type="text" id="movimientos-desde" class="form-control" data-fecha="{{ $desde->toDateString() }}" data-fecha-ar>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Hasta</label>
                <input type="text" id="movimientos-hasta" class="form-control" data-fecha="{{ $hasta->toDateString() }}" data-fecha-ar>
            </div>
            <div class="col-md-6 text-md-end">
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-movimientos">
                    <i class="fas fa-file-export me-1"></i> Exportar
                </button>
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-movimientos-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Total Cobros</div>
                    <div class="fs-4 fw-bold text-success" id="resumen-total-cobros">$ 0,00</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Total Pagos</div>
                    <div class="fs-4 fw-bold text-danger" id="resumen-total-pagos">$ 0,00</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Resultado</div>
                    <div class="fs-4 fw-bold" id="resumen-resultado">$ 0,00</div>
                </div></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header" data-bs-toggle="collapse" data-bs-target="#seccion-cobros" role="button">
                <span class="fw-bold text-success">Cobros</span>
                <span class="float-end" id="seccion-cobros-total">$ 0,00</span>
            </div>
            <div class="collapse" id="seccion-cobros">
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="tabla-desglose-cobros">
                        <thead><tr><th></th><th>Cuenta</th><th class="text-end">Monto</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header" data-bs-toggle="collapse" data-bs-target="#seccion-pagos" role="button">
                <span class="fw-bold text-danger">Pagos</span>
                <span class="float-end" id="seccion-pagos-total">$ 0,00</span>
            </div>
            <div class="collapse" id="seccion-pagos">
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="tabla-desglose-pagos">
                        <thead><tr><th></th><th>Cuenta</th><th class="text-end">Monto</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.TesoreriaConfig = {
        rutas: {
            movimientosData: @json(route('tesoreria.movimientos.data')),
            movimientosExport: @json(route('tesoreria.movimientos.export')),
            movimientosPdf: @json(route('tesoreria.movimientos.pdf')),
        },
    };
</script>
@vite(['resources/js/tesoreria.js'])
@endsection
