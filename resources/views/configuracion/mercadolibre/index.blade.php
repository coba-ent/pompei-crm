@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Mercado Libre</h4>
                <p class="text-muted mb-0">
                    Conectá tu cuenta de Mercado Libre para operar publicaciones, stock y ventas desde el CRM.
                </p>
            </div>
        </div>

        <div id="ml-advertencias-entorno"></div>

        @include('configuracion.mercadolibre._panel_estado')

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Credenciales de la aplicación</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-credenciales-ml">
                    <i class="fas fa-pencil-alt me-1"></i> Editar
                </button>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label text-muted mb-1">App ID</label>
                        <div class="fw-bold" id="ml-info-client-id">—</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted mb-1">Clave secreta</label>
                        <div class="fw-bold" id="ml-info-secret">—</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted mb-1">Sitio</label>
                        <div class="fw-bold" id="ml-info-site">—</div>
                    </div>
                </div>
                <hr>
                <label class="form-label text-muted mb-1">Redirect URI (registrala tal cual en el DevCenter)</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="ml-redirect-uri" readonly>
                    <button class="btn btn-outline-secondary" type="button" id="btn-copiar-redirect-uri">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                </div>

                <div class="alert alert-info mt-3 mb-0 small">
                    <strong>Permisos funcionales a habilitar en el DevCenter</strong> — no se piden por la
                    API, hay que activarlos en la aplicación antes de la primera vinculación:
                    <ul class="mb-0 mt-1">
                        <li><strong>Usuarios</strong> — lectura (activo por defecto, es lo único que usa esta etapa)</li>
                        <li><strong>Publicación y sincronización</strong> — lectura y escritura (etapas siguientes)</li>
                        <li><strong>Ventas y envíos</strong> — lectura y escritura (etapas siguientes)</li>
                        <li><strong>Comunicación pre y postventa</strong> — lectura y escritura (etapas siguientes)</li>
                    </ul>
                    <div class="mt-2">
                        Habilitalos todos desde el arranque: el alcance del token queda congelado con los
                        permisos que la aplicación tenía al momento de autorizar. Autorizá siempre con la
                        <strong>cuenta principal</strong> de Mercado Libre — un usuario operador/colaborador
                        no puede otorgar el permiso.
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Modo sólo lectura</h6>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="ml-modo-solo-lectura">
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">
                    Bloquea toda escritura hacia Mercado Libre (publicaciones, stock, precios), registrándola
                    en el historial en vez de ejecutarla. Permite apuntar a la cuenta real del negocio sin
                    riesgo mientras se sigue desarrollando el resto del módulo.
                </p>
                <div class="alert alert-warning mt-3 mb-0 d-none" id="ml-aviso-solo-lectura">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Modo sólo lectura activo: las escrituras hacia Mercado Libre están bloqueadas.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">Ventas de Mercado Libre</h6>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    <strong>Riesgo de sobreventa:</strong> mientras la sincronización de stock hacia
                    Mercado Libre no esté disponible, una venta manual cargada en el CRM no le avisa a
                    Mercado Libre. Mercado Libre puede seguir ofreciendo unidades que ya no están
                    disponibles.
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info me-1"></i>
                    Las Ventas se registran por el monto <strong>bruto</strong> de los productos (sin
                    descontar la comisión de Mercado Libre): el saldo de la cuenta "Mercado Pago" en el
                    CRM no va a coincidir con el saldo real de Mercado Pago, que está neto de comisiones.
                </div>

                <form id="form-ventas-ml">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="ml-creacion-automatica">
                                <label class="form-check-label" for="ml-creacion-automatica">Creación automática de ventas</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Frecuencia de sincronización</label>
                            <select class="form-select" id="ml-frecuencia-sync">
                                <option value="5">Cada 5 minutos</option>
                                <option value="10">Cada 10 minutos</option>
                                <option value="15">Cada 15 minutos</option>
                                <option value="30">Cada 30 minutos</option>
                                <option value="60">Cada hora</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Antigüedad de la primera sincronización (días)</label>
                            <input type="number" min="1" max="365" class="form-control" id="ml-dias-primera-sync">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Depósito de descuento de stock</label>
                            <select class="form-select" id="ml-deposito-id" style="width:100%">
                                {{-- Se nombra el depósito que se va a usar realmente: decir sólo
                                     "por defecto del CRM" obliga a adivinar cuál es, y cargar stock
                                     en otro deja la publicación desfasada sin ningún aviso. --}}
                                <option value="">Usar el depósito por defecto{{ $depositoPorDefecto ? ' — '.$depositoPorDefecto->nombre : '' }}</option>
                                @foreach ($depositos as $deposito)
                                    <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Mercado Libre publica el stock de <strong id="ml-deposito-efectivo">{{ $depositoEfectivo?->nombre ?? '—' }}</strong>.
                                El stock cargado en otros depósitos no se publica.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Categoría de las Ventas</label>
                            <select class="form-select" id="ml-categoria-venta-id" style="width:100%">
                                <option value="">Sin categoría</option>
                                @foreach ($categoriasVenta as $categoria)
                                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lista de Precios que gestiona Mercado Libre</label>
                            <select class="form-select" id="ml-lista-precio-id" style="width:100%">
                                <option value="">Sin Lista de Precios (sin sincronización de precios)</option>
                                @foreach ($listasPrecio as $lista)
                                    <option value="{{ $lista->id }}">{{ $lista->nombre }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Cuando cambia el precio de un producto vinculado dentro de esta lista, el CRM
                                lo envía de inmediato a la publicación de Mercado Libre correspondiente.
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small" id="ml-ultima-sync-info"></div>
                            <div class="text-muted small" id="ml-stock-ultima-sync-info"></div>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary" id="btn-guardar-ventas-ml">Guardar</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">Historial de operaciones</h6>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Desde</label>
                        <input type="date" class="form-control form-control-sm" id="ml-historial-desde">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Hasta</label>
                        <input type="date" class="form-control form-control-sm" id="ml-historial-hasta">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Resultado</label>
                        <select class="form-select form-select-sm" id="ml-historial-resultado">
                            <option value="">Todos</option>
                            <option value="exito">Éxito</option>
                            <option value="error">Error</option>
                            <option value="bloqueada">Bloqueada</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btn-filtrar-historial">Filtrar</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped w-100" id="tabla-ml-operaciones">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Operación</th>
                                <th>Sentido</th>
                                <th>Resultado</th>
                                <th>Código</th>
                                <th>Duración</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

@include('configuracion.mercadolibre._modal_credenciales')
@include('configuracion.mercadolibre._modal_desconectar')
@include('configuracion.mercadolibre._modal_reemplazo_cuenta')
@endsection

@section('local-js')
@if (session('ml_exito'))
<script>document.addEventListener('DOMContentLoaded', function () { if (window.toastr) { window.toastr.success(@json(session('ml_exito'))); } });</script>
@endif
@if (session('ml_error'))
<script>document.addEventListener('DOMContentLoaded', function () { if (window.toastr) { window.toastr.error(@json(session('ml_error'))); } });</script>
@endif
@if (session('ml_info'))
<script>document.addEventListener('DOMContentLoaded', function () { if (window.toastr) { window.toastr.info(@json(session('ml_info'))); } });</script>
@endif
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
        },
        sitios: @json(\App\Services\MercadoLibre\Sitios::paraSelect()),
    };
</script>
@vite(['resources/js/mercadolibre.js'])
@endsection
