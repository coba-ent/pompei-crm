<div class="dropdown">
    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        Acciones
    </button>
    <ul class="dropdown-menu">
        <li>
            <a class="dropdown-item js-editar-vinculacion" href="#"
               data-id="{{ $vinculacion->id }}"
               data-ml-item-id="{{ $vinculacion->ml_item_id }}"
               data-titulo-ml="{{ $vinculacion->titulo_ml }}"
               data-producto-id="{{ $vinculacion->producto_id }}"
               data-producto-nombre="{{ optional($vinculacion->producto)->nombre }}">
                Editar
            </a>
        </li>
        {{-- spec 115 (FR-002): sólo la acción que corresponde al tipo actual. Otros tipos (ej. Gratuita)
             quedan fuera de alcance y no la ofrecen. --}}
        @php($tipo = $vinculacion->listing_type_id)
        @if ($tipo === \App\Models\Integraciones\MercadoLibrePublicacionProducto::TIPO_CLASICA)
            <li><a class="dropdown-item js-cambiar-tipo" href="#" data-id="{{ $vinculacion->id }}" data-destino="gold_pro">Pasar a Premium</a></li>
        @elseif ($tipo === \App\Models\Integraciones\MercadoLibrePublicacionProducto::TIPO_PREMIUM)
            <li><a class="dropdown-item js-cambiar-tipo" href="#" data-id="{{ $vinculacion->id }}" data-destino="gold_special">Pasar a Clásica</a></li>
        @elseif ($tipo === null)
            <li><a class="dropdown-item js-cambiar-tipo" href="#" data-id="{{ $vinculacion->id }}" data-destino="">Cambiar tipo</a></li>
        @endif
        <li><a class="dropdown-item text-danger js-eliminar-vinculacion" href="#" data-id="{{ $vinculacion->id }}">Eliminar</a></li>
    </ul>
</div>
