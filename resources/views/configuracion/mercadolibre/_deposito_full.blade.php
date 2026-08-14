{{--
    spec 065/FR-015/FR-026 — selector del depósito para publicaciones Full.

    Vive en un partial a propósito: el bloque de configuración de ventas de Mercado Libre
    está duplicado en `configuracion/mercadolibre/index.blade.php` y en `_tab.blade.php`,
    y tocar sólo una deja la pantalla diciendo cosas distintas según por dónde se entre.

    Ojo con la diferencia contra el selector de al lado: el general ofrece "Usar el depósito
    por defecto", éste NO. El depósito Full no tiene fallback (data-model §ml_configuracion):
    caer al por defecto escribiría las existencias del centro de distribución de Mercado
    Libre sobre un depósito físico real del negocio.
--}}
<div class="col-md-6">
    <label class="form-label">Depósito para publicaciones Full</label>
    <select class="form-select" id="ml-deposito-full-id" style="width:100%">
        <option value="">Sin configurar</option>
        @foreach ($depositos as $deposito)
            <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
        @endforeach
    </select>
    <div class="invalid-feedback d-block d-none" id="error-ml-deposito-full-id"></div>
    <div class="form-text">
        Representa la mercadería que Mercado Libre tiene en su centro de distribución. El CRM
        <strong>no</strong> le publica stock: lo lee de Mercado Libre y lo refleja acá.
        @if ($depositoFullEfectivo)
            Actualmente: <strong id="ml-deposito-full-efectivo">{{ $depositoFullEfectivo->nombre }}</strong>.
        @endif
    </div>

    @if ($publicacionesFull > 0 && ! $depositoFullEfectivo)
        <div class="alert alert-warning mt-2 mb-0 py-2 px-3" id="aviso-deposito-full-faltante">
            <i class="fas fa-triangle-exclamation me-1"></i>
            Tenés {{ $publicacionesFull }} {{ $publicacionesFull === 1 ? 'publicación' : 'publicaciones' }}
            en Full pero no configuraste un depósito para Full. Su stock no se está reflejando en el CRM
            y sus ventas se imputan al depósito general.
        </div>
    @endif
</div>
