<div class="dropdown">
    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        Acciones
    </button>
    <ul class="dropdown-menu">
        <li>
            <a class="dropdown-item js-editar-vinculacion" href="#"
               data-id="{{ $vinculacion->id }}"
               data-variant-id="{{ $vinculacion->variant_id }}"
               data-tn-product-id="{{ $vinculacion->tn_product_id }}"
               data-nombre-variante="{{ $vinculacion->nombre_variante_tn }}"
               data-producto-id="{{ $vinculacion->producto_id }}"
               data-producto-nombre="{{ optional($vinculacion->producto)->nombre }}">
                Editar
            </a>
        </li>
        <li>
            <a class="dropdown-item text-danger js-eliminar-vinculacion" href="#" data-id="{{ $vinculacion->id }}">Eliminar</a>
        </li>
    </ul>
</div>
