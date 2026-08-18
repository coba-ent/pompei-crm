@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-3">
            <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h4 class="mb-0 text-primary fw-bold">Inicio</h4>
                @includeIf('dashboard._periodo')
            </div>
        </div>

        @includeIf('dashboard._kpis')

        <div class="row">
            <div class="col-lg-5">
                @includeIf('dashboard._totales')
            </div>
            <div class="col-lg-7">
                @includeIf('dashboard._grafico-mensual')
            </div>
        </div>

        @if ($permisos['tesoreria'])
        <div class="row">
            <div class="col-lg-6">
                @includeIf('dashboard._tesoreria')
            </div>
            <div class="col-lg-6">
                @includeIf('dashboard._cuentas-corrientes')
            </div>
        </div>
        @endif

        @includeIf('dashboard._donas')

        @includeIf('dashboard._rankings')

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.DashboardConfig = {
        rutas: {
            kpis: @json(route('dashboard.kpis')),
            totales: @json(route('dashboard.totales')),
            graficoMensual: @json(route('dashboard.grafico-mensual')),
            donas: @json(route('dashboard.donas')),
            rankings: @json(route('dashboard.rankings')),
        },
        periodoInicial: 'mes_actual',
        permisos: @json($permisos),
    };
</script>
@vite(['resources/js/dashboard.js'])
@endsection
