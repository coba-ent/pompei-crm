@php($activo = $usuario->activo)
<div class="btn-group btn-group-sm" role="group">
    <button type="button" class="btn btn-outline-primary js-usuario-editar" data-id="{{ $usuario->id }}" title="Editar">
        <i class="fas fa-pencil-alt"></i>
    </button>
    <button type="button"
            class="btn btn-outline-{{ $activo ? 'warning' : 'success' }} js-usuario-estado"
            data-id="{{ $usuario->id }}"
            data-activo="{{ $activo ? 1 : 0 }}"
            title="{{ $activo ? 'Inactivar' : 'Reactivar' }}">
        <i class="fas fa-{{ $activo ? 'ban' : 'check' }}"></i>
    </button>
</div>
