@extends('layouts.default')

@php
    $funcionDepositos = $funciones->firstWhere('clave', 'depositos');
    $funcionMercadoLibre = $funciones->firstWhere('clave', 'mercadolibre');
    $funcionTiendanube = $funciones->firstWhere('clave', 'tiendanube');
    $funcionFacturacionElectronica = $funciones->firstWhere('clave', 'facturacion_electronica');
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Configuración & Ajustes</h4>
            </div>
        </div>

        {{-- Los tabs de Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica siempre se
             renderizan en el DOM (para poder mostrarlos/ocultarlos sin recargar la página al
             togglear la función desde "Funciones Avanzadas", FR-007b) pero quedan con "d-none"
             si la función no está activa — no aparecen en la lista de tabs (FR-007a). --}}
        <ul class="nav nav-tabs mb-3" id="configuracion-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-funciones-btn" data-bs-toggle="tab" data-bs-target="#tab-funciones" type="button" role="tab">
                    <i class="fas fa-sliders-h me-1"></i> Funciones Avanzadas
                </button>
            </li>
            <li class="nav-item {{ $funcionDepositos?->activa ? '' : 'd-none' }}" role="presentation" data-tab-clave="depositos">
                <button class="nav-link" id="tab-depositos-btn" data-bs-toggle="tab" data-bs-target="#tab-depositos" type="button" role="tab">
                    <i class="{{ $funcionDepositos->icono ?? 'fas fa-warehouse' }} me-1"></i> Depósitos
                </button>
            </li>
            <li class="nav-item {{ $funcionMercadoLibre?->activa ? '' : 'd-none' }}" role="presentation" data-tab-clave="mercadolibre">
                <button class="nav-link" id="tab-mercadolibre-btn" data-bs-toggle="tab" data-bs-target="#tab-mercadolibre" type="button" role="tab">
                    <i class="{{ $funcionMercadoLibre->icono ?? 'fas fa-shopping-cart' }} me-1"></i> Mercado Libre
                </button>
            </li>
            <li class="nav-item {{ $funcionTiendanube?->activa ? '' : 'd-none' }}" role="presentation" data-tab-clave="tiendanube">
                <button class="nav-link" id="tab-tiendanube-btn" data-bs-toggle="tab" data-bs-target="#tab-tiendanube" type="button" role="tab">
                    <i class="{{ $funcionTiendanube->icono ?? 'fas fa-store' }} me-1"></i> Tiendanube
                </button>
            </li>
            <li class="nav-item {{ $funcionFacturacionElectronica?->activa ? '' : 'd-none' }}" role="presentation" data-tab-clave="facturacion_electronica">
                <button class="nav-link" id="tab-arca-btn" data-bs-toggle="tab" data-bs-target="#tab-arca" type="button" role="tab">
                    <i class="{{ $funcionFacturacionElectronica->icono ?? 'fas fa-file-invoice' }} me-1"></i> Facturación Electrónica
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-ventas-btn" data-bs-toggle="tab" data-bs-target="#tab-ventas" type="button" role="tab">
                    <i class="fas fa-cash-register me-1"></i> Ventas
                </button>
            </li>
        </ul>

        <div class="tab-content" id="configuracion-tabs-content">
            <div class="tab-pane fade show active" id="tab-funciones" role="tabpanel">
                @include('configuracion.funciones._tab')
            </div>
            <div class="tab-pane fade" id="tab-depositos" role="tabpanel" data-tab-pane-clave="depositos">
                @include('configuracion.depositos._tab')
            </div>
            <div class="tab-pane fade" id="tab-mercadolibre" role="tabpanel" data-tab-pane-clave="mercadolibre">
                @include('configuracion.mercadolibre._tab')
            </div>
            <div class="tab-pane fade" id="tab-tiendanube" role="tabpanel" data-tab-pane-clave="tiendanube">
                @include('configuracion.tiendanube._tab')
            </div>
            <div class="tab-pane fade" id="tab-arca" role="tabpanel" data-tab-pane-clave="facturacion_electronica">
                @include('configuracion.facturacion-electronica._tab')
            </div>
            <div class="tab-pane fade" id="tab-ventas" role="tabpanel">
                @include('configuracion.ventas._tab')
            </div>
        </div>

    </div>
</div>

@include('presupuestos._modal_vendedor')
@include('presupuestos._modal_categoria')
@endsection

@section('local-js')
<script>
    window.ConfiguracionTabsData = {
        clavesFuncionConTab: ['depositos', 'mercadolibre', 'tiendanube', 'facturacion_electronica'],
    };
</script>
@vite(['resources/js/configuracion-tabs.js', 'resources/js/funciones-avanzadas.js'])

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

<script>
    window.MercadoLibreConfig = {
        rutas: {
            estado: @json(route('configuracion.mercadolibre.estado')),
            guardar: @json(route('configuracion.mercadolibre.guardar')),
            guardarVentas: @json(route('configuracion.mercadolibre.ventas.configurar')),
            modoSoloLectura: @json(route('configuracion.mercadolibre.modoSoloLectura')),
            probar: @json(route('configuracion.mercadolibre.probar')),
            desconectar: @json(route('configuracion.mercadolibre.desconectar')),
            operaciones: @json(route('configuracion.mercadolibre.operaciones')),
            conectar: @json(route('configuracion.mercadolibre.conectar')),
            pendiente: @json(route('configuracion.mercadolibre.pendiente')),
            confirmarReemplazo: @json(route('configuracion.mercadolibre.confirmarReemplazo')),
            descartarPendiente: @json(route('configuracion.mercadolibre.descartarPendiente')),
            vendedorStore: @json(route('vendedores.store')),
            vendedorUpdateBase: @json(url('vendedores')),
            vendedorDestroyBase: @json(url('vendedores')),
            categoriaVentaStore: @json(route('categorias.venta.store')),
            categoriaUpdateBase: @json(url('categorias')),
            categoriaDestroyBase: @json(url('categorias')),
        },
        sitios: @json(\App\Services\MercadoLibre\Sitios::paraSelect()),
        vendedores: @json($vendedores->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre])),
        categorias: @json($categoriasVenta->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'es_sistema' => $c->es_sistema])),
    };
</script>
@vite(['resources/js/mercadolibre.js'])

<script>
    window.TiendanubeConfig = {
        rutas: {
            estadoRest: @json(route('configuracion.tiendanube.estadoRest')),
            desconectarRest: @json(route('configuracion.tiendanube.desconectarRest')),
            modoSoloLectura: @json(route('configuracion.tiendanube.modoSoloLectura')),
            guardarVentas: @json(route('configuracion.tiendanube.ventas.configurar')),
            historial: @json(route('configuracion.tiendanube.historial')),
            vendedorStore: @json(route('vendedores.store')),
            vendedorUpdateBase: @json(url('vendedores')),
            vendedorDestroyBase: @json(url('vendedores')),
            categoriaVentaStore: @json(route('categorias.venta.store')),
            categoriaUpdateBase: @json(url('categorias')),
            categoriaDestroyBase: @json(url('categorias')),
        },
        vendedores: @json($vendedores->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre])),
        categorias: @json($categoriasVenta->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'es_sistema' => $c->es_sistema])),
    };
</script>
@vite(['resources/js/tiendanube.js'])

<script>
(function () {
    const rutas = {
        certificado: @json(route('configuracion.arca.certificado.guardar')),
        puntoVenta: @json(route('configuracion.arca.puntos-venta.guardar')),
        puntoVentaEstadoBase: @json(url('configuracion/arca/puntos-venta')),
    };

    function toast(tipo, mensaje) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje);
        }
    }

    document.getElementById('form-certificado-arca').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch(rutas.certificado, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
            body: formData,
        })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) { throw data; }
                toast('success', data.mensaje);
                window.location.reload();
            })
            .catch((err) => toast('error', err.message || 'No se pudo guardar el certificado.'));
    });

    document.getElementById('form-punto-venta').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        const payload = Object.fromEntries(formData.entries());
        payload.por_defecto = formData.has('por_defecto');
        fetch(rutas.puntoVenta, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
            },
            body: JSON.stringify(payload),
        })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) { throw data; }
                toast('success', data.mensaje);
                window.location.reload();
            })
            .catch((err) => toast('error', err.message || 'No se pudo guardar el Punto de Venta.'));
    });

    document.querySelectorAll('.js-punto-venta-estado').forEach((input) => {
        input.addEventListener('change', function () {
            fetch(rutas.puntoVentaEstadoBase + '/' + this.dataset.id + '/estado', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({ activo: this.checked }),
            })
                .then((r) => r.json())
                .then((data) => toast('success', data.mensaje))
                .catch(() => toast('error', 'No se pudo actualizar el Punto de Venta.'));
        });
    });
})();
</script>

<script>
    window.ConfiguracionVentasConfig = {
        rutas: { guardar: @json(route('configuracion.ventas.guardar')) },
    };
</script>
@vite(['resources/js/configuracion-ventas.js'])
@endsection
