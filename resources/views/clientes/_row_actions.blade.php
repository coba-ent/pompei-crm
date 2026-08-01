@php($activo = $cliente->activo)
<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-ellipsis-v"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <button type="button" class="dropdown-item js-cliente-ver" data-id="{{ $cliente->id }}">
                <i class="fas fa-eye me-2 text-info"></i> Ver
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item js-cliente-editar" data-id="{{ $cliente->id }}">
                <i class="fas fa-pencil-alt me-2 text-primary"></i> Editar
            </button>
        </li>
        <li>
            <button type="button"
                    class="dropdown-item js-cliente-estado"
                    data-id="{{ $cliente->id }}"
                    data-activo="{{ $activo ? 1 : 0 }}">
                <i class="fas fa-{{ $activo ? 'ban' : 'check' }} me-2 text-{{ $activo ? 'warning' : 'success' }}"></i>
                {{ $activo ? 'Inactivar' : 'Reactivar' }}
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item js-cliente-eliminar" data-id="{{ $cliente->id }}">
                <i class="fas fa-trash-alt me-2 text-danger"></i> Eliminar
            </button>
        </li>
        @can('informes.ver')
            <li>
                <a class="dropdown-item" href="{{ route('informes.cuenta-corriente.index', ['cliente_id' => $cliente->id]) }}">
                    <i class="fas fa-file-invoice-dollar me-2 text-secondary"></i> Cta Cte
                </a>
            </li>
        @endcan
    </ul>
</div>
