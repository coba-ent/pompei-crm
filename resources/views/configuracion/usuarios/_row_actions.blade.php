@php($activo = $usuario->activo)
<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"></button>
    <ul class="dropdown-menu">
        <li>
            <button type="button" class="dropdown-item js-usuario-editar" data-id="{{ $usuario->id }}">
                <i class="fas fa-pencil-alt me-2 text-primary"></i> Editar
            </button>
        </li>
        <li>
            <button type="button"
                    class="dropdown-item js-usuario-estado"
                    data-id="{{ $usuario->id }}"
                    data-activo="{{ $activo ? 1 : 0 }}">
                <i class="fas fa-{{ $activo ? 'ban' : 'check' }} me-2 text-{{ $activo ? 'warning' : 'success' }}"></i>
                {{ $activo ? 'Inactivar' : 'Reactivar' }}
            </button>
        </li>
    </ul>
</div>
