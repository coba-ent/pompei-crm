@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-3">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Informe de Gastos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <div class="input-group d-inline-flex align-items-stretch" style="width:230px">
                    <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión">
                    <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>

        {{-- Panel de filtros, siempre visible (como en Contagram). --}}
        <div class="card mb-3">
            <div class="card-body py-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Categoría</label>
                        <select id="filtro-categoria" class="form-select" multiple>
                            @foreach ($categoriasRaiz as $c)
                                <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Subcategoría</label>
                        <select id="filtro-subcategoria" class="form-select" multiple>
                            @foreach ($subcategorias as $s)
                                <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Medio de pago</label>
                        <select id="filtro-cuenta" class="form-select" multiple>
                            @foreach ($cuentas as $ct)
                                <option value="{{ $ct->id }}">{{ $ct->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Estado del pago</label>
                        <select id="filtro-estado-pago" class="form-select">
                            <option value="">Todos</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="pagado">Pagado</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Usuario</label>
                        <select id="filtro-usuario" class="form-select" multiple>
                            @foreach ($usuarios as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button type="button" class="btn btn-light" id="btn-limpiar-filtros">Limpiar</button>
                        <button type="button" class="btn btn-primary" id="btn-aplicar-filtros">
                            <i class="fas fa-search me-1"></i> Buscar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bloque Desde / Hasta / Gasto Total, tal como lo encabeza Contagram. --}}
        <div class="gastos-resumen mb-3">
            <div class="gastos-resumen__celda">
                <span class="gastos-resumen__rotulo">Desde</span>
                <span class="gastos-resumen__valor" id="resumen-desde">&mdash;</span>
            </div>
            <div class="gastos-resumen__celda">
                <span class="gastos-resumen__rotulo">Hasta</span>
                <span class="gastos-resumen__valor" id="resumen-hasta">&mdash;</span>
            </div>
            <div class="gastos-resumen__celda">
                <span class="gastos-resumen__rotulo">Gasto Total</span>
                <span class="gastos-resumen__valor gastos-resumen__valor--total" id="resumen-total">$ 0,00</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-expandir-todo">
                <i class="fas fa-angle-double-down me-1"></i> Expandir todo
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-colapsar-todo">
                <i class="fas fa-angle-double-up me-1"></i> Colapsar todo
            </button>
            <div class="ms-auto d-flex gap-2">
                @can('informes.exportar')
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
                @endcan
            </div>
        </div>

        {{-- Árbol Categoría → Subcategoría. Lo dibuja informe-gastos.js a partir de `stats`. --}}
        <div id="gastos-arbol"></div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.InformeGastosConfig = {
        rutas: {
            stats: @json(route('informes.gastos.stats')),
            grupo: @json(route('informes.gastos.grupo')),
            exportar: @json(route('informes.gastos.exportar')),
            pdf: @json(route('informes.gastos.pdf')),
        },
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/informe-gastos.js'])
@endsection
