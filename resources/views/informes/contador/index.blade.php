@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Información para tu Contador</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <button type="button" class="btn btn-outline-primary me-2" id="btn-iva-digital" disabled title="Elegí un mes y un año para habilitar la descarga">
                    <i class="fas fa-file-archive me-1"></i> IVA Digital
                </button>
                <button type="button" class="btn btn-outline-secondary me-2" id="btn-enviar-contador" data-bs-toggle="modal" data-bs-target="#modal-envio-contador">
                    <i class="fas fa-paper-plane me-1"></i> Enviar a tu Contador
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        {{-- Dos pestañas: Libro IVA Ventas / Libro IVA Compras. Cada una mantiene su propio
             período, filtros y columnas visibles en el cliente (FR-030) — nunca #fragmento. --}}
        <ul class="nav nav-tabs mb-3" id="tabs-contador" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-btn-ventas" data-bs-toggle="tab" data-bs-target="#tab-ventas" type="button" role="tab" data-pestana="ventas">
                    Libro IVA Ventas
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-btn-compras" data-bs-toggle="tab" data-bs-target="#tab-compras" type="button" role="tab" data-pestana="compras">
                    Libro IVA Compras
                </button>
            </li>
        </ul>

        <div class="tab-content">
            @foreach (['ventas' => 'Ventas', 'compras' => 'Compras'] as $pestana => $etiqueta)
            <div class="tab-pane fade {{ $pestana === 'ventas' ? 'show active' : '' }}" id="tab-{{ $pestana }}" role="tabpanel">
                <div class="card">
                    <div class="card-body">

                        {{-- Mes/Año: <select> nativos, nunca input type="date" (CLAUDE.md #6, research §D8). --}}
                        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                            <select class="form-select form-select-sm js-mes" style="width:140px" data-pestana="{{ $pestana }}">
                                <option value="">Mes</option>
                                @foreach (['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'] as $num => $nombre)
                                    <option value="{{ (int) $num }}">{{ $nombre }}</option>
                                @endforeach
                            </select>
                            <select class="form-select form-select-sm js-anio" style="width:110px" data-pestana="{{ $pestana }}">
                                <option value="">Año</option>
                                @foreach ($anios as $anio)
                                    <option value="{{ $anio }}">{{ $anio }}</option>
                                @endforeach
                            </select>

                            @if ($pestana === 'ventas')
                            {{-- FR-014: sólo IVA Ventas. Nunca aparece en Compras (FR-014a). --}}
                            <div class="form-check ms-2">
                                <input class="form-check-input js-arca" type="checkbox" id="chk-arca" checked>
                                <label class="form-check-label" for="chk-arca">Facturas Aprobadas por ARCA</label>
                            </div>
                            <div class="form-check ms-2">
                                <input class="form-check-input js-manuales" type="checkbox" id="chk-manuales">
                                <label class="form-check-label" for="chk-manuales">Facturas Manuales (NO enviadas a ARCA o Esperando Aprobación de ARCA)</label>
                            </div>
                            @endif

                            <button type="button" class="btn btn-info ms-auto" data-bs-toggle="collapse" data-bs-target="#panel-filtros-{{ $pestana }}">
                                <i class="fas fa-filter me-1"></i> Filtros
                            </button>
                            <button type="button" class="btn btn-outline-secondary js-colvis" data-pestana="{{ $pestana }}">
                                <i class="fas fa-columns me-1"></i> Columnas
                            </button>
                        </div>

                        {{-- Panel de filtros (T049): los 8 campos del contrato, rotulados según la pestaña. --}}
                        <div class="collapse mb-3" id="panel-filtros-{{ $pestana }}">
                            <div class="card card-body bg-light">
                                <div class="row g-2">
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1">Id</label>
                                        <input type="number" class="form-control form-control-sm js-f-id" data-pestana="{{ $pestana }}">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">Tipo de Comprobante</label>
                                        <select class="js-f-tipo-comprobante form-select form-select-sm" data-pestana="{{ $pestana }}" multiple>
                                            @foreach ($tiposComprobante as $t)
                                                <option value="{{ $t }}">{{ $t }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">N° de Comprobante</label>
                                        <input type="text" class="form-control form-control-sm js-f-nro-comprobante" data-pestana="{{ $pestana }}">
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label small mb-1">{{ $pestana === 'ventas' ? 'Cliente' : 'Proveedor' }}</label>
                                        <select class="js-f-contraparte form-select form-select-sm" style="width:100%" data-pestana="{{ $pestana }}" multiple></select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">N° de CUIT</label>
                                        <input type="text" class="form-control form-control-sm js-f-cuit" data-pestana="{{ $pestana }}">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">Condición de IVA</label>
                                        <select class="js-f-condicion-iva form-select form-select-sm" data-pestana="{{ $pestana }}" multiple>
                                            @foreach ($condicionesIva as $c)
                                                <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">{{ $pestana === 'ventas' ? 'Medio de Cobro' : 'Medio de Pago' }}</label>
                                        <select class="js-f-cuenta-tesoreria form-select form-select-sm" data-pestana="{{ $pestana }}">
                                            <option value="">Todos</option>
                                            @foreach ($cuentasTesoreria as $ct)
                                                <option value="{{ $ct->id }}">{{ $ct->nombre }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">Provincia</label>
                                        <select class="js-f-provincia form-select form-select-sm" data-pestana="{{ $pestana }}">
                                            <option value="">Todas</option>
                                            @foreach ($provincias as $p)
                                                <option value="{{ $p->nombre }}">{{ $p->nombre }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-primary btn-sm js-aplicar-filtros" data-pestana="{{ $pestana }}">Aplicar</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm js-limpiar-filtros" data-pestana="{{ $pestana }}">Limpiar</button>
                                </div>
                            </div>
                        </div>

                        {{-- Barra de 5 totales con operadores visuales (FR-010). --}}
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="totales-{{ $pestana }}">
                            <div class="widget-stat card mb-0"><div class="card-body p-2 px-3"><p class="mb-0 small">No Gravados/Exentos</p><h5 class="mb-0 js-tot-no-gravados">$ 0,00</h5></div></div>
                            <span class="fs-4 text-muted">+</span>
                            <div class="widget-stat card mb-0"><div class="card-body p-2 px-3"><p class="mb-0 small">Gravados</p><h5 class="mb-0 js-tot-gravados">$ 0,00</h5></div></div>
                            <span class="fs-4 text-muted">+</span>
                            <div class="widget-stat card mb-0"><div class="card-body p-2 px-3"><p class="mb-0 small">IVA Total</p><h5 class="mb-0 js-tot-iva">$ 0,00</h5></div></div>
                            <span class="fs-4 text-muted">+</span>
                            <div class="widget-stat card mb-0"><div class="card-body p-2 px-3"><p class="mb-0 small">Perc. IVA/IIBB</p><h5 class="mb-0 js-tot-perc">$ 0,00</h5></div></div>
                            <span class="fs-4 text-muted">=</span>
                            <div class="widget-stat card mb-0 border border-primary"><div class="card-body p-2 px-3"><p class="mb-0 small fw-bold">Total Facturado</p><h5 class="mb-0 text-primary js-tot-facturado">$ 0,00</h5></div></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-sm w-100 js-tabla" id="tabla-informe-contador-{{ $pestana }}" data-pestana="{{ $pestana }}">
                                <thead>
                                    <tr>
                                        <th>Id</th>
                                        <th>Emisión</th>
                                        <th>Tipo</th>
                                        <th>N° de Comprobante</th>
                                        <th>{{ $pestana === 'ventas' ? 'Cliente' : 'Proveedor' }}</th>
                                        <th>CUIT/DNI</th>
                                        <th>Condición de IVA</th>
                                        <th>Neto No Gravado</th>
                                        <th>Neto Exento</th>
                                        <th>Neto Gravado</th>
                                        <th>IVA 2,5%</th>
                                        <th>IVA 5%</th>
                                        <th>IVA 10,5%</th>
                                        <th>IVA 21%</th>
                                        <th>IVA 27%</th>
                                        <th>Perc. IVA</th>
                                        <th>Perc. IIBB</th>
                                        <th>Imp. Internos</th>
                                        <th>Imp. Municipales</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        {{-- FR-006/FR-007: estado inicial sin período elegido. --}}
                        <p class="text-muted text-center py-4 js-mensaje-vacio" id="mensaje-vacio-{{ $pestana }}">
                            Utilizá los filtros y generá tu informe a medida
                        </p>

                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- FR-024. --}}
        <p class="text-muted small mt-2" id="leyenda-actualizado"></p>

    </div>
</div>

@include('informes.contador._modal_envio')

@endsection

@section('local-js')
<script>
    window.InformeContadorConfig = {
        rutas: {
            ventas: {
                data: @json(route('informes.contador.ventas.data')),
                stats: @json(route('informes.contador.ventas.stats')),
                exportar: @json(route('informes.contador.ventas.exportar')),
                contraparte: @json(route('clientes.opciones')),
            },
            compras: {
                data: @json(route('informes.contador.compras.data')),
                stats: @json(route('informes.contador.compras.stats')),
                exportar: @json(route('informes.contador.compras.exportar')),
                contraparte: @json(route('proveedores.opciones')),
            },
        },
        ivaDigital: @json(route('informes.contador.iva-digital')),
    };

    window.EnvioContadorConfig = {
        rutas: {
            adjuntosPrevistos: @json(route('informes.contador.adjuntos-previstos')),
            enviar: @json(route('informes.contador.enviar')),
        },
        mailContador: @json($mailContador),
        nombreNegocio: @json($nombreNegocio),
    };
</script>
@vite(['resources/js/informe-contador.js', 'resources/js/envio-contador.js'])
@endsection
