@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Depósitos</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <button type="button" class="btn btn-primary" id="btn-configurar-depositos">
                    <i class="fas fa-warehouse me-1"></i> Configurar Depósitos
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <p class="text-muted mb-0">
                    Administrá los depósitos disponibles para el control de stock de Productos.
                    Los depósitos activos aparecen en el filtro y el selector de "Depósito" de la
                    pantalla de Productos.
                </p>
            </div>
        </div>

    </div>
</div>

@include('configuracion._modal_depositos')
@endsection

@section('local-js')
<script>
    window.DepositosConfig = {
        rutas: {
            data: @json(route('configuracion.depositos.data')),
            store: @json(route('configuracion.depositos.store')),
            base: @json(url('configuracion/depositos')),
        },
    };
</script>
@vite(['resources/js/configuracion-depositos.js'])
@if (request()->boolean('crear'))
<script>
    (function () {
        var intentos = 0;
        var intervalo = setInterval(function () {
            intentos++;
            var btn = document.getElementById('btn-configurar-depositos');
            var eventos = (window.jQuery && jQuery._data && btn) ? jQuery._data(btn, 'events') : null;
            if ((eventos && eventos.click) || intentos >= 100) {
                clearInterval(intervalo);
                if (btn) { btn.click(); }
            }
        }, 100);
    })();
</script>
@endif
@endsection
