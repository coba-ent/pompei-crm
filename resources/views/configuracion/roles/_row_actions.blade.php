<div class="btn-group btn-group-sm" role="group">
    <button type="button" class="btn btn-outline-primary js-rol-editar" data-id="{{ $rol->id }}" title="Editar">
        <i class="fas fa-pencil-alt"></i>
    </button>
    @unless ($rol->es_sistema)
        <button type="button" class="btn btn-outline-danger js-rol-eliminar" data-id="{{ $rol->id }}" title="Eliminar">
            <i class="fas fa-trash-alt"></i>
        </button>
    @endunless
</div>
