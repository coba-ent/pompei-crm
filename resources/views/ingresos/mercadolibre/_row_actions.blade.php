@php
    $puedeConvertir = $orden->estado_conversion->habilitaCrearVenta();
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
        Acciones
    </button>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item js-ver-detalle" href="#" data-id="{{ $orden->id }}">Ver detalle</a></li>
        @if ($orden->venta_id)
            <li><a class="dropdown-item" href="{{ route('ventas.show', $orden->venta_id) }}">Ir a la Venta</a></li>
        @elseif ($puedeConvertir && \Illuminate\Support\Facades\Route::has('ingresos.mercadolibre.convertir'))
            <li><a class="dropdown-item" href="{{ route('ingresos.mercadolibre.convertir', $orden) }}">Crear Venta</a></li>
        @else
            <li>
                <span class="dropdown-item disabled" title="{{ $orden->motivo_detalle }}">
                    Crear Venta @if($orden->motivo) ({{ $orden->motivo->etiqueta() }}) @endif
                </span>
            </li>
        @endif
    </ul>
</div>
