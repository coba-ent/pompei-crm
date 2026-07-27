@php($activo = $producto->activo)
<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-ellipsis-v"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item js-producto-ver" href="#" data-id="{{ $producto->id }}">Ver</a></li>
        <li><a class="dropdown-item js-producto-editar" href="#" data-id="{{ $producto->id }}">Editar</a></li>
        <li><a class="dropdown-item text-danger js-producto-eliminar" href="#" data-id="{{ $producto->id }}">Eliminar</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item js-producto-copia" href="#" data-id="{{ $producto->id }}">Crear Copia</a></li>
        <li>
            <a class="dropdown-item js-producto-estado" href="#" data-id="{{ $producto->id }}" data-activo="{{ $activo ? 1 : 0 }}">
                {{ $activo ? 'Inactivar' : 'Reactivar' }}
            </a>
        </li>
        @unless ($producto->esServicio())
            <li><a class="dropdown-item" href="{{ route('informes.stock.index', ['producto_id' => $producto->id]) }}">Movimientos</a></li>
            <li><a class="dropdown-item js-producto-aumentar" href="#" data-id="{{ $producto->id }}">Aumentar Stock</a></li>
            <li><a class="dropdown-item js-producto-disminuir" href="#" data-id="{{ $producto->id }}">Disminuir Stock</a></li>
        @endunless
    </ul>
</div>
