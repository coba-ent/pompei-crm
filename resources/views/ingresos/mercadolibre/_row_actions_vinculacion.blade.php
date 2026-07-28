<div class="dropdown">
    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
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
        <li><a class="dropdown-item text-danger js-eliminar-vinculacion" href="#" data-id="{{ $vinculacion->id }}">Eliminar</a></li>
    </ul>
</div>
