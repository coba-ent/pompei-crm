@php($activo = $proveedor->activo)
<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"></button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <button type="button" class="dropdown-item js-proveedor-ver" data-id="{{ $proveedor->id }}">
                <i class="fas fa-eye me-2 text-info"></i> Ver
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item js-proveedor-editar" data-id="{{ $proveedor->id }}">
                <i class="fas fa-pencil-alt me-2 text-primary"></i> Editar
            </button>
        </li>
        <li>
            <button type="button"
                    class="dropdown-item js-proveedor-estado"
                    data-id="{{ $proveedor->id }}"
                    data-activo="{{ $activo ? 1 : 0 }}">
                <i class="fas fa-{{ $activo ? 'ban' : 'check' }} me-2 text-{{ $activo ? 'warning' : 'success' }}"></i>
                {{ $activo ? 'Inactivar' : 'Reactivar' }}
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item js-proveedor-eliminar" data-id="{{ $proveedor->id }}">
                <i class="fas fa-trash-alt me-2 text-danger"></i> Eliminar
            </button>
        </li>
        {{-- Deep-link al informe de Cta Cte de este proveedor (spec 067 FR-038), espejo exacto
             del que Clientes ya tenía. Cierra el "Próximamente" de documentacion_principal_crm §4.3. --}}
        @can('informes.ver')
            <li>
                <a class="dropdown-item" href="{{ route('informes.cuenta-corriente-proveedores.index', ['proveedor_id' => $proveedor->id]) }}">
                    <i class="fas fa-file-invoice-dollar me-2 text-secondary"></i> Cta Cte
                </a>
            </li>
        @endcan
    </ul>
</div>
