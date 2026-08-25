@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Cuenta Corriente Proveedores</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        @php($abrirMovimientos = (bool) ($proveedorPreseleccionado ?? null))

        <ul class="nav nav-tabs mb-3" id="cuenta-corriente-proveedores-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link @unless($abrirMovimientos) active @endunless" id="tab-saldos-proveedores-btn" data-bs-toggle="tab" data-bs-target="#tab-saldos-proveedores" type="button" role="tab" aria-selected="{{ $abrirMovimientos ? 'false' : 'true' }}">
                    Saldos Proveedores
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link @if($abrirMovimientos) active @endif" id="tab-movimientos-btn" data-bs-toggle="tab" data-bs-target="#tab-movimientos" type="button" role="tab" aria-selected="{{ $abrirMovimientos ? 'true' : 'false' }}">
                    Movimientos
                </button>
            </li>
        </ul>

        <div class="tab-content" id="cuenta-corriente-proveedores-tabs-content">

            {{-- Tab: Saldos Proveedores --}}
            <div class="tab-pane fade @unless($abrirMovimientos) show active @endunless" id="tab-saldos-proveedores" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-saldos-proveedor" class="form-select">
                                    <option value="">Todos</option>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex justify-content-end align-items-end">
                                <span id="dt-buttons-saldos-proveedores"></span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="tabla-saldos-proveedores" class="table table-hover display responsive nowrap" style="width:100%">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="align-bottom">Proveedor</th>
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
            <div class="tab-pane fade @if($abrirMovimientos) show active @endif" id="tab-movimientos" role="tabpanel">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-movimientos-proveedor" class="form-select">
                                    <option value="">Todos</option>
                                    @if ($proveedorPreseleccionado)
                                        <option value="{{ $proveedorPreseleccionado->id }}" selected>{{ $proveedorPreseleccionado->nombre }}</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Operación</label>
                                <select id="filtro-movimientos-operacion" class="form-select">
                                    <option value="">Todas</option>
                                    <option value="compra">Compra</option>
                                    <option value="pago">Pago</option>
                                    <option value="nota_credito">Nota de Crédito</option>
                                    <option value="nota_debito">Nota de Débito</option>
                                    <option value="saldo_inicial">Saldo Inicial</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Emisión</label>
                                <input type="text" id="filtro-movimientos-rango-fechas" class="form-control" placeholder="Todas las fechas">
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
                                        <th>Proveedor</th>
                                        <th>Operación</th>
                                        <th>Categoría</th>
                                        <th class="text-end">Total Compra</th>
                                        <th class="text-end">Pagado</th>
                                        <th class="text-end">A Pagar</th>
                                        <th>N° de Comprobante</th>
                                        <th>Medio de Pago</th>
                                        <th>Descripción</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-success" id="btn-exportar-movimientos">
                                <i class="fas fa-file-excel me-1"></i> Exportar
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btn-exportar-movimientos-pdf">
                                <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

@include('informes.cuenta-corriente-proveedores._modal_ficha')
@endsection

@section('local-js')
<script>
    window.InformeCuentaCorrienteProveedoresConfig = {
        rutas: {
            index: @json(route('informes.cuenta-corriente-proveedores.index')),
            saldosData: @json(route('informes.cuenta-corriente-proveedores.saldos.data')),
            movimientosData: @json(route('informes.cuenta-corriente-proveedores.movimientos.data')),
            fichaProveedor: @json(route('informes.cuenta-corriente-proveedores.proveedor.show', ['proveedor' => '__ID__'])),
            exportar: @json(route('informes.cuenta-corriente-proveedores.exportar')),
            pdf: @json(route('informes.cuenta-corriente-proveedores.pdf')),
            exportarMovimientos: @json(route('informes.cuenta-corriente-proveedores.movimientos.exportar')),
            pdfMovimientos: @json(route('informes.cuenta-corriente-proveedores.movimientos.pdf')),
            proveedoresOpciones: @json(route('proveedores.opciones')),
        },
        proveedorId: {{ $proveedorPreseleccionado ? (int) $proveedorPreseleccionado->id : 'null' }},
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/informe-cuenta-corriente-proveedores.js'])
@endsection
