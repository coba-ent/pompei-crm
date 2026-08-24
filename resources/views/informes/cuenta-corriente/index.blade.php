@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Cuenta Corriente</h4>
            </div>
        </div>

        @php($abrirMovimientos = (bool) ($clientePreseleccionado ?? null))

        <ul class="nav nav-tabs mb-3" id="cuenta-corriente-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link @unless($abrirMovimientos) active @endunless" id="tab-saldos-clientes-btn" data-bs-toggle="tab" data-bs-target="#tab-saldos-clientes" type="button" role="tab" aria-controls="tab-saldos-clientes" aria-selected="{{ $abrirMovimientos ? 'false' : 'true' }}">
                    Saldos Clientes
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link @if($abrirMovimientos) active @endif" id="tab-movimientos-btn" data-bs-toggle="tab" data-bs-target="#tab-movimientos" type="button" role="tab" aria-controls="tab-movimientos" aria-selected="{{ $abrirMovimientos ? 'true' : 'false' }}">
                    Movimientos
                </button>
            </li>
        </ul>

        <div class="tab-content" id="cuenta-corriente-tabs-content">

            {{-- Tab: Saldos Clientes --}}
            <div class="tab-pane fade @unless($abrirMovimientos) show active @endunless" id="tab-saldos-clientes" role="tabpanel" aria-labelledby="tab-saldos-clientes-btn">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Cliente</label>
                                <select id="filtro-saldos-cliente" class="form-select">
                                    <option value="">Todos</option>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex justify-content-end align-items-end">
                                <span id="dt-buttons-saldos-clientes"></span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="tabla-saldos-clientes" class="table table-hover display responsive nowrap" style="width:100%">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="align-bottom">Cliente</th>
                                        <th rowspan="2" class="align-bottom text-end">A Vencer</th>
                                        <th colspan="4" class="text-center">Vencido</th>
                                        <th rowspan="2" class="align-bottom text-end">Total</th>
                                    </tr>
                                    <tr>
                                        <th class="text-end">0 y 30</th>
                                        <th class="text-end">31 y 60</th>
                                        <th class="text-end">61 y 90</th>
                                        <th class="text-end">&gt;90</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Tab: Movimientos --}}
            <div class="tab-pane fade @if($abrirMovimientos) show active @endif" id="tab-movimientos" role="tabpanel" aria-labelledby="tab-movimientos-btn">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Cliente</label>
                                <select id="filtro-movimientos-cliente" class="form-select" multiple>
                                    @if ($clientePreseleccionado)
                                        <option value="{{ $clientePreseleccionado->id }}" selected>{{ $clientePreseleccionado->nombre }}</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Operación</label>
                                <select id="filtro-movimientos-operacion" class="form-select" multiple>
                                    <option value="venta">Venta</option>
                                    <option value="cobro">Cobro</option>
                                    <option value="nota_credito">Nota de Crédito</option>
                                    <option value="nota_debito">Nota de Débito</option>
                                    <option value="saldo_inicial">Saldo Inicial</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Emisión</label>
                                <input type="text" id="filtro-movimientos-rango-fechas" class="form-control" placeholder="Todas las fechas">
                                <input type="hidden" id="filtro-movimientos-fecha-desde">
                                <input type="hidden" id="filtro-movimientos-fecha-hasta">
                            </div>
                            <div class="col-md-2 d-flex justify-content-end align-items-end">
                                <span id="dt-buttons-movimientos"></span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="tabla-movimientos" class="table table-hover display responsive nowrap" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Id</th>
                                        <th>Emisión</th>
                                        <th>Cliente</th>
                                        <th>Operación</th>
                                        <th>Categoría</th>
                                        <th class="text-end">Total Venta</th>
                                        <th class="text-end">Cobrado</th>
                                        <th class="text-end">A Cobrar</th>
                                        <th>N° de Comprobante</th>
                                        <th>Medio de Cobro</th>
                                        <th>Descripción</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.InformeCuentaCorrienteConfig = {
        rutas: {
            index: @json(route('informes.cuenta-corriente.index')),
            saldosData: @json(route('informes.cuenta-corriente.saldos.data')),
            movimientosData: @json(route('informes.cuenta-corriente.movimientos.data')),
            clientesOpciones: @json(route('clientes.opciones')),
        },
        clienteId: {{ $clientePreseleccionado ? (int) $clientePreseleccionado->id : 'null' }},
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/informe-cuenta-corriente.js'])
@endsection
