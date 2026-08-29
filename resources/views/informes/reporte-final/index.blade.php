@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Reporte Final</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                @can('informes.exportar')
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar Resumen
                </button>
                @endcan
            </div>
        </div>

        {{-- Las dos lecturas del período. "Ventas Vs. Compras" (devengado) es la de arranque. --}}
        <ul class="nav nav-tabs mb-3" id="vistas-reporte-final">
            <li class="nav-item">
                <button class="nav-link active" type="button" data-vista="devengado">Ventas Vs. Compras</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-vista="caja">Cobros Vs Pagos</button>
            </li>
        </ul>

        {{-- Banner informativo de cada vista (FR-031). El texto cambia con la pestaña: la
             diferencia sobre los gastos pendientes es justamente lo que distingue las dos bases. --}}
        <div class="alert alert-info alert-dismissible fade show" id="banner-vista" role="alert">
            <span id="banner-texto"></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <div class="input-group" style="width:230px">
                        <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión" autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-6 col-md-2">
                        <div class="widget-stat card mb-0 h-100">
                            <div class="card-body p-3">
                                <p class="mb-1">Desde</p>
                                <h5 class="mb-0" id="cab-desde">&mdash;</h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="widget-stat card mb-0 h-100">
                            <div class="card-body p-3">
                                <p class="mb-1">Hasta</p>
                                <h5 class="mb-0" id="cab-hasta">&mdash;</h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="widget-stat card mb-0 h-100">
                            <div class="card-body p-3">
                                <p class="mb-1">Total Ingresos</p>
                                <h4 class="mb-0 text-success" id="cab-ingresos">$ 0,00</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="widget-stat card mb-0 h-100">
                            <div class="card-body p-3">
                                <p class="mb-1">Total Egresos</p>
                                <h4 class="mb-0 text-danger" id="cab-egresos">$ 0,00</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-2">
                        <div class="widget-stat card mb-0 h-100 border border-primary">
                            <div class="card-body p-3">
                                <p class="mb-1 fw-bold">Resultado</p>
                                <h4 class="mb-0 text-primary" id="cab-resultado">$ 0,00</h4>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-muted small mb-0 mt-2">Resultado = Total Ingresos &minus; Total Egresos</p>
            </div>
        </div>

        {{-- El árbol NO es una DataTable: es un agregado de decenas de filas con subtotales y
             checkboxes de simulación que recalculan en el cliente. Única desviación de la regla
             #1 de CLAUDE.md, justificada en plan.md §Complexity Tracking. --}}
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tabla-reporte-final">
                        <thead>
                            <tr>
                                <th style="width:60px" class="text-center">Activo</th>
                                <th>Descripción</th>
                                <th class="text-end" style="width:180px">Total</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpo-reporte-final">
                            <tr><td colspan="3" class="text-center text-muted">Cargando&hellip;</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.ReporteFinalConfig = {
        rutas: {
            data: @json(route('informes.reporte-final.data')),
            exportar: @json(route('informes.reporte-final.exportar')),
            pdf: @json(route('informes.reporte-final.pdf')),
        },
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/reporte-final.js'])
@endsection
