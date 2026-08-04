<div class="row align-items-center mb-4">
    <div class="col-12">
        <h4 class="mb-0 text-primary fw-bold">Funciones Avanzadas</h4>
        <p class="text-muted mb-0">
            Activá las funciones adicionales del CRM. Las que todavía no están construidas
            se muestran deshabilitadas.
        </p>
    </div>
</div>

<div id="lista-funciones">
    @foreach ($funciones as $funcion)
        <div class="card mb-3 {{ $funcion->disponible ? '' : 'opacity-75' }}"
             data-funcion-id="{{ $funcion->id }}"
             data-funcion-clave="{{ $funcion->clave }}">
            <div class="card-body d-flex flex-wrap align-items-center">
                <div class="fs-2 text-primary me-3">
                    <i class="{{ $funcion->icono ?? 'fas fa-toggle-on' }}"></i>
                </div>
                <div class="flex-grow-1 me-3">
                    <h6 class="mb-1 fw-bold">
                        {{ $funcion->nombre }}
                        @unless ($funcion->disponible)
                            <span class="badge bg-secondary ms-2">Próximamente</span>
                        @endunless
                    </h6>
                    <p class="text-muted mb-0">{{ $funcion->descripcion }}</p>
                </div>
                <div class="d-flex align-items-center gap-3 ms-auto">
                    @if ($funcion->clave === 'mercadolibre' && \App\Models\Integraciones\MercadoLibreCuenta::conectada()->exists() && Route::has('ingresos.mercadolibre.index'))
                        <a href="{{ route('ingresos.mercadolibre.index') }}" class="btn btn-sm btn-outline-primary">
                            Ver mis Órdenes
                        </a>
                    @endif
                    <div class="form-check form-switch mb-0">
                        <input
                            class="form-check-input js-funcion-toggle"
                            type="checkbox"
                            role="switch"
                            id="funcion-{{ $funcion->id }}"
                            {{ $funcion->activa ? 'checked' : '' }}
                            {{ $funcion->disponible ? '' : 'disabled' }}
                        >
                        <label class="form-check-label js-funcion-label" for="funcion-{{ $funcion->id }}">
                            {{ $funcion->activa ? 'Sí' : 'No' }}
                        </label>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@include('configuracion._modal_confirmar_desactivar_ml')
