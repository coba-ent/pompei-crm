<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"></button>
    <ul class="dropdown-menu">
        <li>
            <button type="button" class="dropdown-item js-rol-editar" data-id="{{ $rol->id }}">
                <i class="fas fa-pencil-alt me-2 text-primary"></i> Editar
            </button>
        </li>
        @unless ($rol->es_sistema)
            <li>
                <button type="button" class="dropdown-item js-rol-eliminar" data-id="{{ $rol->id }}">
                    <i class="fas fa-trash-alt me-2 text-danger"></i> Eliminar
                </button>
            </li>
        @endunless
    </ul>
</div>
