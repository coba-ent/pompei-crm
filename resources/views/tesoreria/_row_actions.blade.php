{{-- Menú de fila del ledger: sólo Editar y Eliminar (informe §5, captura 159). --}}
<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-ellipsis-v"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item js-movimiento-editar" href="#" data-id="{{ $id }}">Editar</a></li>
        <li><a class="dropdown-item text-danger js-movimiento-eliminar" href="#" data-id="{{ $id }}">Eliminar</a></li>
    </ul>
</div>
