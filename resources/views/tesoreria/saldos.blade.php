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
                <a class="nav-link active" href="{{ route('tesoreria.saldos') }}">Saldos</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('tesoreria.movimientos') }}">Movimientos</a>
            </li>
        </ul>

        <div class="row align-items-center mb-3 g-2">
            <div class="col-md-4">
                <label class="form-label mb-1">Buscar por Fecha</label>
                <input type="date" id="tesoreria-fecha-corte" class="form-control" value="{{ $fecha->toDateString() }}">
            </div>
            <div class="col-md-8 text-md-end">
                <button type="button" class="btn btn-success" id="btn-movimiento-entre-cuentas">
                    <i class="fas fa-plus me-1"></i> Movimiento entre Cuentas
                </button>
                <button type="button" class="btn btn-outline-secondary" id="btn-configurar-cuentas" title="Configurar Cuentas de Tesorería">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </div>

        <div class="row" id="tesoreria-saldos-bloques">
            <div class="col-md-6">
                <div class="card border-success mb-3">
                    <div class="card-header bg-success-light">
                        <h6 class="mb-0">A Cobrar</h6>
                    </div>
                    <div class="card-body p-0" style="max-height:260px; overflow-y:auto;">
                        <table class="table table-sm mb-0" id="tabla-a-cobrar">
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex justify-content-between fw-bold">
                        <span>Total A Cobrar</span>
                        <span id="total-a-cobrar">$ 0,00</span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-danger mb-3">
                    <div class="card-header bg-danger-light">
                        <h6 class="mb-0">A Pagar</h6>
                    </div>
                    <div class="card-body p-0" style="max-height:260px; overflow-y:auto;">
                        <table class="table table-sm mb-0" id="tabla-a-pagar">
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex justify-content-between fw-bold">
                        <span>Total A Pagar</span>
                        <span id="total-a-pagar">$ 0,00</span>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card border-info mb-3">
                    <div class="card-header bg-info-light d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Disponible</h6>
                        <span class="fw-bold">Total: <span id="total-disponible">$ 0,00</span></span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted">Cajas</h6>
                                <table class="table table-sm" id="tabla-cajas">
                                    <tbody></tbody>
                                </table>
                                <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                    <span>Total Cajas</span>
                                    <span id="total-cajas">$ 0,00</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Bancos</h6>
                                <table class="table table-sm" id="tabla-bancos">
                                    <tbody></tbody>
                                </table>
                                <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                    <span>Total Bancos</span>
                                    <span id="total-bancos">$ 0,00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@include('tesoreria._modal_transferencia')
@include('tesoreria._modal_cuenta')
@include('tesoreria._config_cuentas')
@endsection

@section('local-js')
<script>
    window.TesoreriaConfig = {
        rutas: {
            saldosData: @json(route('tesoreria.saldos.data')),
            configData: @json(route('tesoreria.config.data')),
            cuentasOpciones: @json(route('tesoreria.cuentas.opciones')),
            cuentasStore: @json(route('tesoreria.cuentas.store')),
            cuentasBase: @json(url('tesoreria/cuentas')),
            cuentaCorrienteClientes: @json(route('informes.cuenta-corriente.index')),
            transferenciasStore: @json(route('tesoreria.transferencias.store')),
        },
        saldosIniciales: @json($saldos),
    };
</script>
@vite(['resources/js/tesoreria.js'])
@endsection
