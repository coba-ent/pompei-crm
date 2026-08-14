@php
    // spec 066 (FR-020): una orden en estado excepcional mantiene la acción VISIBLE y
    // HABILITADA — es la única vía para resolverla. Deshabilitarla dejaría a la persona
    // sin forma de decidir, que es justamente lo contrario de lo que pide la feature.
    // Los problemas de datos siguen deshabilitando: ahí no hay nada que confirmar.
    $motivoExcepcional = $orden->venta_id ? null : $orden->motivoExcepcional();
    $puedeConvertir = $orden->estado_conversion->habilitaCrearVenta() || $motivoExcepcional !== null;
    // spec 063 (T012/T013): un aviso de cancelación/reembolso/mediación posterior a la
    // conversión — sólo aplica a órdenes que YA tienen Venta (FR-007).
    $esAvisoCancelacion = $orden->venta_id
        && $orden->estado_conversion === \App\Enums\MercadoLibre\EstadoConversion::RequiereAtencion
        && in_array($orden->motivo, \App\Enums\MercadoLibre\MotivoRequiereAtencion::motivosDeCancelacionPosterior(), true);
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-{{ $orden->estado_conversion->color() }} dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        Acciones
    </button>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item js-ver-detalle" href="#" data-id="{{ $orden->id }}">Ver detalle</a></li>
        @if ($orden->venta_id)
            <li><a class="dropdown-item" href="{{ route('ventas.show', $orden->venta_id) }}">Ir a la Venta</a></li>
        @elseif ($puedeConvertir && \Illuminate\Support\Facades\Route::has('ingresos.mercadolibre.convertir'))
            <li>
                <a class="dropdown-item {{ $motivoExcepcional ? 'text-warning' : '' }}"
                   href="{{ route('ingresos.mercadolibre.convertir', $orden) }}"
                   @if ($motivoExcepcional) title="{{ $motivoExcepcional->etiqueta() }}" @endif>
                    Crear Venta @if ($motivoExcepcional) (requiere confirmación) @endif
                </a>
            </li>
        @else
            <li>
                <span class="dropdown-item disabled" title="{{ $orden->motivo_detalle }}">
                    Crear Venta @if($orden->motivo) ({{ $orden->motivo->etiqueta() }}) @endif
                </span>
            </li>
        @endif
        @if ($esAvisoCancelacion)
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item text-warning js-descartar-aviso-ml" href="#" data-id="{{ $orden->id }}" title="{{ $orden->motivo_detalle }}">
                    Descartar aviso
                </a>
            </li>
        @endif
    </ul>
</div>
