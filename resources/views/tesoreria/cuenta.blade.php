@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-3">
            <div class="col-12">
                <a href="{{ route('tesoreria.saldos') }}" class="text-muted small"><i class="fas fa-arrow-left me-1"></i> Volver a Saldos</a>
                <h4 class="mb-0 text-primary fw-bold">{{ $cuenta->nombre }}</h4>
            </div>
        </div>

        <div class="card">
            <div class="card-body">

                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros-ledger">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <span id="dt-buttons-tesoreria-ledger"></span>
                    <button type="button" class="btn btn-success js-movimiento-entre-cuentas">
                        <i class="fas fa-plus me-1"></i> Movimiento entre Cuentas
                    </button>
                    <a href="#" class="btn btn-outline-secondary" id="btn-exportar-ledger">
                        <i class="fas fa-file-export me-1"></i> Exportar
                    </a>
                </div>

                <div class="collapse show mb-3" id="panel-filtros-ledger">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Elija Tipo de Operación</label>
                                <select id="filtro-tipo-operacion" class="form-select">
                                    <option value="">Todas</option>
                                    <option value="saldo_inicial">Saldo Inicial</option>
                                    <option value="movimiento_entre_cuentas">Movimiento entre Cuenta</option>
                                    <option value="cobro">Cobro</option>
                                    <option value="pago">Pago</option>
                                    <option value="gasto">Gasto</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Desde</label>
                                <input type="date" id="filtro-ledger-desde" class="form-control" value="{{ $desde->toDateString() }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Hasta</label>
                                <input type="date" id="filtro-ledger-hasta" class="form-control" value="{{ $hasta->toDateString() }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tabla-ledger" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th class="dt-acciones-caret"></th>
                                <th>Id</th>
                                <th>Fecha</th>
                                <th>Operación</th>
                                <th>Detalles</th>
                                <th class="text-end">Ingreso</th>
                                <th class="text-end">Egreso</th>
                                <th class="text-end">Balance</th>
                                <th>N° Factura</th>
                                <th>Observación</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div>
        </div>

    </div>
</div>

@include('tesoreria._modal_transferencia')
@include('tesoreria._modal_movimiento_editar')
@endsection

@section('local-js')
<script>
    window.TesoreriaConfig = {
        rutas: {
            cuentasOpciones: @json(route('tesoreria.cuentas.opciones')),
            transferenciasStore: @json(route('tesoreria.transferencias.store')),
            ledgerData: @json(route('tesoreria.cuentas.data', $cuenta)),
            ledgerExport: @json(route('tesoreria.cuentas.export', $cuenta)),
            movimientosBase: @json(url('tesoreria/movimientos')),
        },
    };
</script>
@vite(['resources/js/tesoreria.js'])
@endsection
